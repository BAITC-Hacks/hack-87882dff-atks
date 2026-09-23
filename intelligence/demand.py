"""Build an auditable demand foundation from the official monthly-sales source.

Run: python -m intelligence.demand. Raw workbooks and loader outputs are not edited.
"""
import hashlib
import json
from dataclasses import asdict

import pandas as pd

from .loader import DATA_DIR, FILES
from .reconciliation import reconcile
from .outliers import BulkConfig, detect_bulk_outliers
from .stockout import detect_stockouts
from .lost_demand import estimate_lost_demand


def build_demand(monthly_sales):
    frame = monthly_sales.rename(columns={'demand': 'observed_demand'}).copy()
    frame['date'] = pd.to_datetime(frame.date)
    if frame.duplicated(['sku', 'date']).any():
        raise ValueError('Official monthly sales must have unique SKU/month keys')
    frame['year'], frame['month'] = frame.date.dt.year, frame.date.dt.month
    frame['source'] = 'official_monthly_sales'
    # Negative net values indicate correction-like netting, not identified return documents.
    frame['is_return_or_correction'] = frame.observed_demand.lt(0).astype('boolean')
    frame.loc[frame.observed_demand.isna(), 'is_return_or_correction'] = pd.NA
    return frame[['sku', 'date', 'year', 'month', 'observed_demand', 'source',
                  'is_return_or_correction', 'source_row']]


def main():
    output = DATA_DIR / 'processed'
    hashes = {name: hashlib.sha256((DATA_DIR / 'raw' / name).read_bytes()).hexdigest() for name in FILES.values()}
    def read(name):
        return pd.read_csv(output / f'{name}.csv', dtype={'sku': 'string', 'document_number': 'string'},
                           keep_default_na=False, na_values=[''], parse_dates=['date'])
    tx, monthly, inventory = read('transactions'), read('monthly_sales'), read('monthly_inventory')
    evidence, pairs, samples = reconcile(tx, monthly)
    evidence['decision'] = {
        'selected_transaction_interpretation': None,
        'demand_source': 'official_monthly_sales',
        'reason': 'None of A–D reliably reproduces the reference. Error rankings conflict, coverage differs by sign/year, and totals disagree substantially. Transaction sales/return semantics remain unresolved.',
        'best_rmse_method': min(evidence['methods'], key=lambda k: evidence['methods'][k]['excluding_missing_monthly_sales']['rmse']),
        'best_mae_method': min(evidence['methods'], key=lambda k: evidence['methods'][k]['excluding_missing_monthly_sales']['mae']),
    }
    demand = build_demand(monthly)
    config = BulkConfig()
    regular = detect_bulk_outliers(demand, config)
    stockouts = detect_stockouts(inventory)
    corrected = estimate_lost_demand(regular, stockouts)
    corrections = demand[demand.is_return_or_correction.fillna(False)]
    bulk = regular[regular.is_bulk_outlier]
    # Keep every source transaction, explicitly superseding the earlier sign assumption.
    transaction_audit = tx.drop(columns=['demand', 'movement_type'], errors='ignore').copy()
    transaction_audit['sign'] = 'missing'
    transaction_audit.loc[tx.quantity > 0, 'sign'] = 'positive'
    transaction_audit.loc[tx.quantity < 0, 'sign'] = 'negative'
    transaction_audit.loc[tx.quantity == 0, 'sign'] = 'zero'
    transaction_audit['semantic_status'] = 'unresolved'
    outputs = {'demand': demand, 'regular_demand': regular, 'bulk_outliers_audit': bulk,
               'corrections_audit': corrections, 'transaction_semantics_audit': transaction_audit,
               'stockouts': stockouts, 'corrected_demand': corrected,
               'transaction_reconciliation': pairs, 'transaction_sign_samples': samples}
    for name, frame in outputs.items():
        frame.to_csv(output / f'{name}.csv', index=False, na_rep='')
    lost = corrected.estimated_lost_demand
    raw_unchanged = all(hashes[name] == hashlib.sha256((DATA_DIR / 'raw' / name).read_bytes()).hexdigest() for name in hashes)
    summary = {'bulk_config': asdict(config), 'bulk_outlier_periods': len(bulk),
               'bulk_outlier_skus': int(bulk.sku.nunique()),
               'bulk_quantity_excluded': float(bulk.observed_demand.sum()),
               'negative_net_correction_periods': len(corrections),
               'negative_net_correction_quantity': float(corrections.observed_demand.sum()),
               'stockout_periods_all_inventory': int(stockouts.is_stockout.sum()),
               'unknown_inventory_periods_all_inventory': int(stockouts.is_stockout.isna().sum()),
               'stockout_periods_sales_universe': int(corrected.is_stockout.sum()),
               'unknown_inventory_periods_sales_universe': int(corrected.is_stockout.isna().sum()),
               'estimated_lost_demand': float(lost.sum(min_count=1)) if lost.notna().any() else None,
               'lost_demand_skus': int(corrected.loc[lost > 0, 'sku'].nunique()),
               'skus_affected_by_bulk_or_lost_demand': len(set(bulk.sku) | set(corrected.loc[lost > 0, 'sku'])),
               'correction_status_counts': corrected.correction_status.value_counts().to_dict(),
               'raw_files_unchanged': raw_unchanged,
               'limitations': ['Bulk flags identify abnormal monthly totals, not confirmed one-time orders.',
                              'No customer identifier is available or fabricated.',
                              'Flagged months are excluded from regular demand with NA, not imputed zero.',
                              'Negative monthly net corrections remain in observed demand and the corrections audit.',
                              'Monthly stock snapshots cannot prove continuous availability throughout a month.',
                              'Lost demand is estimated only for observed nonpositive stock with sufficient prior available-stock regular history.',
                              'Unknown inventory leaves lost demand unknown; no uplift is applied.',
                              'Seasonality and trend use strictly prior SKU history; aggregate seasonality units are unspecified and not blended in.',
                              'Legacy transactions.csv demand/movement_type fields are provisional and superseded by the unresolved transaction semantics audit.']}
    (output / 'sign_reconciliation_report.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n')
    (output / 'demand_summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
    if not raw_unchanged:
        raise RuntimeError('Raw workbook hash changed during processing')
    print(json.dumps({'decision': evidence['decision'], 'summary': summary}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
