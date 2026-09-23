"""Re-run the real-data CLI and independently validate the serialized outputs."""
from __future__ import annotations

import hashlib
import json
import random
import subprocess
import sys
from pathlib import Path

import numpy as np
import openpyxl
import pandas as pd

from .loader import DATA_DIR, FILES


def main():
    raw, output = DATA_DIR / 'raw', DATA_DIR / 'processed'
    hashes = {k: hashlib.sha256((raw / name).read_bytes()).hexdigest() for k, name in FILES.items()}
    subprocess.run([sys.executable, '-m', 'intelligence.cli', '--raw-dir', str(raw),
                    '--output-dir', str(output)], check=True)
    quality = json.loads((output / 'quality_report.json').read_text())
    frames = {k: pd.read_csv(output / f'{k}.csv', dtype={'sku': 'string'},
                            keep_default_na=False, na_values=['']) for k in FILES}
    checks = {}
    def check(name, condition):
        checks[name] = bool(condition)

    check('raw_workbooks_unchanged', all(hashes[k] == hashlib.sha256((raw / n).read_bytes()).hexdigest()
                                       for k, n in FILES.items()))
    sku_sets = {k: set(f.sku.dropna()) for k, f in frames.items() if 'sku' in f}
    sales = frames['monthly_sales'][['sku']].drop_duplicates()
    universe = sku_sets['monthly_sales']
    coverage = {}
    for kind, skus in sku_sets.items():
        joined = sales.merge(frames[kind][['sku']].drop_duplicates(), on='sku', how='left',
                             indicator=True, validate='one_to_one')
        matched = int(joined['_merge'].eq('both').sum())
        coverage[kind] = {'unique_skus': len(skus), 'matched_sales_skus': matched,
                          'sales_universe_count': len(universe),
                          'sales_universe_coverage_pct': round(100 * matched / len(universe), 2),
                          'dataset_skus_in_sales_pct': round(100 * matched / len(skus), 2),
                          'sales_skus_missing': sorted(universe - skus),
                          'extra_skus_outside_sales': sorted(skus - universe)}
        check(f'{kind}_sku_no_null_or_whitespace', frames[kind].sku.notna().all()
              and frames[kind].sku.eq(frames[kind].sku.str.strip()).all())
    pairwise = {a: {b: len(x & y) for b, y in sku_sets.items()} for a, x in sku_sets.items()}

    stats = {}
    value_fields = {'transactions': ['quantity', 'demand'], 'monthly_sales': ['demand'],
                    'monthly_inventory': ['stock'], 'moq': ['order_multiple'],
                    'goods_in_transit': ['in_transit_quantity'], 'seasonality': ['value']}
    for kind, frame in frames.items():
        s = stats[kind] = {'rows': len(frame), 'missing': frame.isna().sum().to_dict(), 'numeric': {}}
        keys = quality['datasets'][kind]['duplicate_key_columns']
        s['duplicate_rows'] = int(frame.drop(columns='source_row').duplicated().sum())
        s['duplicate_keys'] = int(frame.duplicated(keys).sum())
        check(f'{kind}_row_count_matches_report', len(frame) == quality['datasets'][kind]['row_count'])
        check(f'{kind}_duplicate_counts_match_report', s['duplicate_rows'] == quality['datasets'][kind]['duplicate_count']
              and s['duplicate_keys'] == quality['datasets'][kind]['duplicate_key_count'])
        for field in value_fields[kind]:
            values = pd.to_numeric(frame[field], errors='coerce')
            check(f'{kind}_{field}_numeric_and_finite', values.notna().equals(frame[field].notna())
                  and np.isfinite(values.dropna()).all())
            s['numeric'][field] = {'min': float(values.min()), 'max': float(values.max()),
                                   'negative': int((values < 0).sum()), 'positive': int((values > 0).sum()),
                                   'zero': int((values == 0).sum()), 'missing': int(values.isna().sum()),
                                   'fractional': int((values.dropna() % 1 != 0).sum())}
        if 'date' in frame:
            dates = pd.to_datetime(frame.date, errors='coerce')
            check(f'{kind}_valid_dates', dates.notna().all())
            s['date_range'] = [dates.min().isoformat(), dates.max().isoformat()]
            if kind in ('monthly_sales', 'monthly_inventory'):
                expected = set(pd.date_range('2024-01-01', '2026-09-01', freq='MS'))
                check(f'{kind}_33_months_per_sku', set(dates) == expected and (dates.dt.day == 1).all()
                      and frame.groupby('sku').date.nunique().eq(33).all())
    tx = frames['transactions']
    check('outgoing_demand_equals_negative_quantity', tx.loc[tx.quantity < 0, 'demand'].eq(-tx.loc[tx.quantity < 0, 'quantity']).all())
    check('positive_movements_have_missing_demand', tx.loc[tx.quantity > 0, 'demand'].isna().all())
    check('missing_quantities_have_missing_demand', tx.loc[tx.quantity.isna(), 'demand'].isna().all())
    check('transaction_demand_nonnegative', tx.demand.dropna().ge(0).all())
    check('moq_order_multiple_positive_integer', frames['moq'].order_multiple.gt(0).all()
          and frames['moq'].order_multiple.mod(1).eq(0).all())
    check('in_transit_quantity_nonnegative', frames['goods_in_transit'].in_transit_quantity.dropna().ge(0).all())
    check('stock_nonnegative', frames['monthly_inventory'].stock.dropna().ge(0).all())

    # A reproducible random sample from the union includes unmatched identifiers too.
    examples = random.Random(42).sample(sorted(set.union(*sku_sets.values())), 10)
    source_examples = {sku: {} for sku in examples}
    raw_sku_fields = {'transactions': 'Код', 'monthly_sales': 'Номенклатура.Код',
                      'monthly_inventory': 'Номенклатура.Код', 'moq': 'Номенклатура.Код',
                      'goods_in_transit': 'Код 1с'}
    for kind, sku_field in raw_sku_fields.items():
        info = quality['datasets'][kind]['source']
        book = openpyxl.load_workbook(raw / FILES[kind], read_only=True, data_only=True)
        source = {}
        try:
            sheet = book[info['sheet']]
            for row_number, row in enumerate(sheet.iter_rows(min_row=info['header_row']), info['header_row']):
                if row_number == info['header_row']:
                    headers = {str(c.value).strip(): i for i, c in enumerate(row) if c.value is not None}
                    continue
                cell = row[headers[sku_field]]
                source[row_number] = (cell.value, cell.number_format, [c.value for c in row])
        finally:
            book.close()
        mismatches = []
        for row in frames[kind][['sku', 'source_row']].drop_duplicates().itertuples(index=False):
            original, number_format, _ = source[row.source_row]
            expected = str(original).strip() if original is not None else None
            if isinstance(original, (int, float)) and float(original).is_integer():
                expected = str(int(original))
                if number_format and set(number_format) == {'0'}:
                    expected = expected.zfill(len(number_format))
            if row.sku != expected:
                mismatches.append({'row': row.source_row, 'original': original, 'processed': row.sku})
            if row.sku in source_examples and kind not in source_examples[row.sku]:
                source_examples[row.sku][kind] = {'raw': original, 'processed': row.sku, 'source_row': row.source_row}
        check(f'{kind}_all_sku_identifiers_match_raw', not mismatches)
        stats[kind]['sku_mismatches'] = mismatches
        # Independently compare every normalized value with its original Excel cell.
        source_field = {'transactions': 'Количество', 'moq': 'Кратность',
                        'goods_in_transit': 'СЭ в пути 24.09'}.get(kind)
        value_field = value_fields[kind][0]
        month_headers = list(headers)[4:37] if kind == 'monthly_inventory' else list(headers)[4:37]
        mismatch_count = 0
        for row in frames[kind].to_dict('records'):
            cells = source[int(row['source_row'])][2]
            if source_field:
                value = cells[headers[source_field]]
            else:
                date = pd.Timestamp(row['date'])
                month_index = (date.year - 2024) * 12 + date.month - 1
                value = cells[headers[month_headers[month_index]]]
            normalized = row[value_field]
            if value is None:
                matches = pd.isna(normalized)
            else:
                matches = not pd.isna(normalized) and float(value) == float(normalized)
            mismatch_count += not matches
        stats[kind]['value_mismatches_against_raw'] = mismatch_count
        check(f'{kind}_all_values_and_nulls_match_raw', mismatch_count == 0)
    result = {'checks': checks, 'all_checks_passed': all(checks.values()), 'stats': stats,
              'join_coverage': coverage, 'pairwise_sku_intersection_counts': pairwise,
              'skus_shared_by_all_five': len(set.intersection(*sku_sets.values())),
              'random_sku_examples_seed_42': source_examples,
              'raw_sha256_before': hashes,
              'transaction_count': len(tx), 'unique_skus_all_datasets': len(set.union(*sku_sets.values())),
              'warehouse_count': int(tx.warehouse.nunique()), 'warehouses': sorted(tx.warehouse.dropna().unique())}
    (output / 'validation_report.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print('\nVALIDATION:', 'PASS' if all(checks.values()) else 'REVIEW REQUIRED')
    print('Failed checks:', [k for k, v in checks.items() if not v])
    print('Join coverage:', json.dumps({k: {a: b for a, b in v.items() if not isinstance(b, list)} for k, v in coverage.items()}))
    print('Shared by all five:', result['skus_shared_by_all_five'])
    print('Numeric statistics:', json.dumps({k: s['numeric'] for k, s in stats.items()}))
    print('SKU examples:', json.dumps(source_examples, ensure_ascii=False))
    print('Saved', output / 'validation_report.json')
    if not all(checks.values()):
        sys.exit(1)


if __name__ == '__main__':
    main()
