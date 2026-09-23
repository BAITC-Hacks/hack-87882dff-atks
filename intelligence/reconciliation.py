"""Empirical sign comparison; unknown values are never replaced with zero."""
import numpy as np
import pandas as pd

METHODS = {'A': 'positive_only', 'B': 'absolute_negative_only',
           'C': 'signed_net', 'D': 'absolute_all'}


def metrics(frame, method, strict=False):
    valid = frame[['demand', method]].dropna()
    error = valid[method] - valid.demand
    unknown = len(frame) - len(valid)
    defined = len(valid) > 0 and (not strict or unknown == 0)
    denominator = len(frame) if strict else len(valid)
    return {
        'pairs': len(frame), 'comparable_pairs': len(valid), 'unassessable_pairs': unknown,
        'mae': float(error.abs().mean()) if defined else None,
        'rmse': float(np.sqrt((error ** 2).mean())) if defined else None,
        'correlation': float(valid[method].corr(valid.demand))
        if defined and valid[method].nunique() > 1 and valid.demand.nunique() > 1 else None,
        'exact_match_rate': float(error.eq(0).sum() / denominator) if denominator else None,
        'near_match_rate': float(error.abs().le(1).sum() / denominator) if denominator else None,
        'aggregate_total_difference': float(error.sum()) if defined else None,
        'reference_total': float(valid.demand.sum()) if defined else None,
        'transaction_total': float(valid[method].sum()) if defined else None,
    }


def reconcile(transactions, monthly_sales):
    tx = transactions.copy()
    tx['date'] = pd.to_datetime(tx.date)
    tx['month_date'] = tx.date.dt.to_period('M').dt.to_timestamp()
    tx['document_type'] = tx.document.str.replace(r'\s+\S+\s+от\s+.*$', '', regex=True)
    masks = {'positive': tx.quantity > 0, 'negative': tx.quantity < 0,
             'zero': tx.quantity == 0, 'missing': tx.quantity.isna()}
    signs, samples = {}, []
    for sign, mask in masks.items():
        subset = tx.loc[mask]
        signs[sign] = {'count': len(subset),
                       'total_quantity': None if sign == 'missing' else float(subset.quantity.sum()),
                       'document_types': subset.document_type.value_counts().to_dict()}
        if len(subset):
            selected = subset.sample(min(4, len(subset)), random_state=42)
            selected = pd.concat([selected, subset.drop_duplicates('document_type')]).drop_duplicates('source_row')
            samples.append(selected.assign(sign=sign))
    tx['A'] = tx.quantity.clip(lower=0)
    tx['B'] = -tx.quantity.clip(upper=0)
    tx['C'] = tx.quantity
    tx['D'] = tx.quantity.abs()
    grouped = tx.groupby(['sku', 'month_date'])
    quantities = grouped[list(METHODS)].sum(min_count=1)
    quantities['missing_transaction_quantities'] = grouped.quantity.apply(lambda x: int(x.isna().sum()))
    # A partial sum is not a complete monthly transaction-derived quantity.
    quantities.loc[quantities.missing_transaction_quantities > 0, list(METHODS)] = np.nan
    quantities = quantities.reset_index().rename(columns={'month_date': 'date'})
    ref = monthly_sales.copy()
    ref['date'] = pd.to_datetime(ref.date)
    pairs = ref.merge(quantities, on=['sku', 'date'], how='inner', validate='one_to_one')
    methods = {}
    for method in METHODS:
        methods[method] = {
            'interpretation': METHODS[method],
            'all_matched_pairs': metrics(pairs, method, strict=True),
            'excluding_missing_monthly_sales': metrics(pairs[pairs.demand.notna()], method),
            'by_year_complete_cases': {str(year): metrics(part, method)
                                       for year, part in pairs.groupby(pairs.date.dt.year)},
        }
    results = {'signs': signs, 'methods': methods, 'matched_sku_months': len(pairs),
               'missing_monthly_sales_in_matched_pairs': int(pairs.demand.isna().sum()),
               'transaction_groups_with_missing_quantities': int((quantities.missing_transaction_quantities > 0).sum()),
               'notes': ['Near match means absolute difference <= 1 source unit.',
                         'Full-set error metrics are undefined if any paired value is unknown.',
                         'Full-set match rates are lower bounds: unknown pairs are not matches.',
                         'Filtered metrics use complete pairs, excluding missing reference and transaction aggregates.',
                         'No absent SKU/month or blank cell is imputed as zero.',
                         'Negative movements are concentrated before the positive transaction coverage; document type alone does not identify returns.'],
               'transaction_sign_by_year': {str(year): {'positive': int((part.quantity > 0).sum()),
                                                         'negative': int((part.quantity < 0).sum())}
                                            for year, part in tx.groupby(tx.date.dt.year)}}
    return results, pairs, pd.concat(samples, ignore_index=True)
