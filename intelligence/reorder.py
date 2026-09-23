"""Single-period procurement; no fabricated lead time, safety stock, or MOQ."""
import json
import math
import numpy as np
import pandas as pd


def recommend(forecasts, inventory, transit, multiples, snapshot_month):
    snapshot_month = pd.Timestamp(snapshot_month)
    inv = inventory.copy()
    inv['date'] = pd.to_datetime(inv.date)
    # Keep the latest known balance and its age; stale balances block automatic orders.
    latest = inv[(inv.date <= snapshot_month) & inv.stock.notna()].sort_values('date').drop_duplicates('sku', keep='last')
    latest = latest[['sku', 'date', 'stock']].rename(columns={'date': 'stock_date', 'stock': 'current_stock'})
    for table in (forecasts, transit, multiples):
        if table.sku.isna().any() or table.sku.duplicated().any():
            raise ValueError('Expected unique, nonmissing product codes in procurement inputs')
    result = forecasts.merge(latest, on='sku', how='left', validate='one_to_one')
    result = result.merge(transit[['sku', 'in_transit_quantity']].rename(columns={'in_transit_quantity': 'in_transit'}),
                          on='sku', how='left', validate='one_to_one')
    result = result.merge(multiples[['sku', 'order_multiple']], on='sku', how='left', validate='one_to_one')
    result['available_stock'] = result.current_stock + result.in_transit
    result['net_requirement'] = (result.forecast_demand - result.available_stock).clip(lower=0)
    result['recommended_quantity'] = np.nan
    result['urgency'] = 'review_required'
    result['transit_reduced_order'] = False
    result['rounding_increased_order'] = False
    result['recommendation_without_transit'] = np.nan
    result['explanation_components'] = ''
    for index, row in result.iterrows():
        issues = []
        for field in ('forecast_demand', 'current_stock', 'in_transit'):
            if pd.isna(row[field]) or not np.isfinite(row[field]) or row[field] < 0:
                issues.append(f'missing_or_invalid_{field}')
        if pd.notna(row.stock_date) and row.stock_date != snapshot_month:
            issues.append('stale_stock_snapshot')
        multiple = row.order_multiple
        if pd.isna(multiple) or not np.isfinite(multiple) or multiple <= 0 or multiple % 1:
            issues.append('missing_or_invalid_order_multiple')
        if not issues:
            quantity = math.ceil(row.net_requirement / multiple) * multiple
            without = math.ceil(max(0, row.forecast_demand - row.current_stock) / multiple) * multiple
            result.loc[index, 'recommended_quantity'] = quantity
            result.loc[index, 'recommendation_without_transit'] = without
            result.loc[index, 'transit_reduced_order'] = quantity < without
            result.loc[index, 'rounding_increased_order'] = quantity > row.net_requirement + 1e-9
            # Urgency is deterministic coverage arithmetic, not a lead-time assessment.
            urgency = 'covered' if quantity == 0 else ('stockout' if row.current_stock == 0 else 'replenish')
            result.loc[index, 'urgency'] = urgency
        result.loc[index, 'explanation_components'] = json.dumps({
            'status': 'review_required' if issues else 'calculated', 'issues': issues,
            'formula': 'ceil(max(0, forecast - stock - transit) / order_multiple) * order_multiple',
            'stock_period': None if pd.isna(row.stock_date) else row.stock_date.isoformat(),
            'lead_time': None, 'safety_stock': None, 'minimum_order_quantity': None,
            'transit_arrival_date': None, 'transit_timing': 'unverified; quantity-only scenario',
            'horizon': 'one forecast month; no lead-time coverage implied'}, ensure_ascii=False)
    return result
