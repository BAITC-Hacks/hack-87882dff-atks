"""Calendar-aligned features built strictly from earlier regular demand."""
import numpy as np
import pandas as pd

NUMERIC_FEATURES = ['month_of_year', 'quarter', 'time_index', 'month_sin', 'month_cos',
                    'lag_1', 'lag_2', 'lag_3', 'lag_6', 'lag_12',
                    'rolling_mean_3', 'rolling_mean_6', 'rolling_mean_12',
                    'rolling_std_3', 'rolling_std_6', 'growth_1', 'growth_3', 'history_mean']
FEATURES = ['sku', *NUMERIC_FEATURES]


def build_features(demand):
    source = demand.copy()
    source['date'] = pd.to_datetime(source.date)
    if source.sku.isna().any() or source.date.isna().any() or source.duplicated(['sku', 'date']).any():
        raise ValueError('Expected unique, nonmissing SKU/month keys')
    if not source.date.dt.is_month_start.all():
        raise ValueError('Expected month-start dates')
    pieces = []
    for sku, group in source.groupby('sku', sort=True):
        dates = pd.date_range(group.date.min(), group.date.max(), freq='MS')
        g = group.set_index('date').reindex(dates).rename_axis('date').reset_index()
        g['sku'] = str(sku)
        target = pd.to_numeric(g.regular_demand, errors='raise')
        past = target.shift(1)
        for lag in (1, 2, 3, 6, 12):
            g[f'lag_{lag}'] = target.shift(lag)
        for window in (3, 6, 12):
            g[f'rolling_mean_{window}'] = past.rolling(window, min_periods=1).mean()
        for window in (3, 6):
            g[f'rolling_std_{window}'] = past.rolling(window, min_periods=2).std()
        g['growth_1'] = (past - target.shift(2)) / target.shift(2).where(target.shift(2) > 0)
        g['growth_3'] = (past - target.shift(4)) / target.shift(4).where(target.shift(4) > 0)
        # Compare matching calendar months so a recurring seasonal peak is not a trend.
        yoy = past / target.shift(13).where(target.shift(13) > 0)
        g['annual_growth_factor'] = yoy.rolling(6, min_periods=3).median().clip(0.5, 2.0)
        g['seasonal_trend_prediction'] = g.lag_12 * g.annual_growth_factor
        g['history_count'] = past.notna().cumsum()
        g['history_mean'] = past.expanding(min_periods=1).mean()
        g['last_history_date'] = g.date.where(target.notna()).ffill().shift(1)
        pieces.append(g)
    result = pd.concat(pieces, ignore_index=True)
    result['year'] = result.date.dt.year
    result['month'] = result.date.dt.month
    result['month_of_year'] = result['month']
    result['quarter'] = result.date.dt.quarter
    result['time_index'] = (result.year - 2024) * 12 + result['month'] - 1
    result['month_sin'] = np.sin(2 * np.pi * result['month'] / 12)
    result['month_cos'] = np.cos(2 * np.pi * result['month'] / 12)
    return result


def forecast_source(corrected):
    """Keep audited outlier exclusions; lift only supported historical stockouts."""
    source = corrected.copy()
    valid = source.correction_status.eq('historical_stockout_correction') & source.regular_demand.notna()
    source.loc[valid, 'regular_demand'] = source.loc[valid, ['regular_demand', 'corrected_demand']].max(axis=1)
    return source


def eligibility(frame):
    """At least six prior usable months and a usable observation within six months."""
    age = (frame.date.dt.year - frame.last_history_date.dt.year) * 12 + frame.date.dt.month - frame.last_history_date.dt.month
    return (frame.history_count >= 6) & (age <= 6)
