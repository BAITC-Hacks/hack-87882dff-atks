"""Stockout evidence from monthly stock snapshots; missing stock stays unknown."""
import pandas as pd


def detect_stockouts(inventory):
    result = inventory[['sku', 'date', 'stock']].copy()
    result['date'] = pd.to_datetime(result.date)
    if result.duplicated(['sku', 'date']).any():
        raise ValueError('Expected unique SKU/month inventory snapshots')
    result['is_stockout'] = result.stock.le(0).astype('boolean')
    result.loc[result.stock.isna(), 'is_stockout'] = pd.NA
    return result
