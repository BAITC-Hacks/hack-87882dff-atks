"""Historical stockout corrections, without forecasting model training."""
import numpy as np
import pandas as pd


def estimate_lost_demand(regular, stockouts, min_history=6, window_months=36):
    if min_history < 2:
        raise ValueError('At least two historical observations are required')
    frame = regular.merge(stockouts[['sku', 'date', 'is_stockout']], on=['sku', 'date'],
                          how='left', validate='one_to_one')
    frame = frame.sort_values(['sku', 'date']).reset_index(drop=True)
    frame['is_stockout'] = frame.is_stockout.astype('boolean')
    frame['estimated_lost_demand'] = np.nan
    frame['corrected_demand'] = frame.regular_demand
    frame['latent_demand_baseline'] = np.nan
    frame['seasonality_factor'] = np.nan
    frame['trend_per_month'] = np.nan
    frame['correction_status'] = 'inventory_unknown'
    for _, group in frame.groupby('sku', sort=False):
        history = []
        for index, row in group.iterrows():
            cutoff = row.date - pd.DateOffset(months=window_months)
            history = [(d, v) for d, v in history if d >= cutoff]
            if pd.isna(row.is_stockout):
                continue
            if not row.is_stockout:
                frame.loc[index, 'estimated_lost_demand'] = 0.0
                frame.loc[index, 'correction_status'] = 'no_stockout'
                if pd.notna(row.regular_demand) and row.regular_demand >= 0:
                    history.append((row.date, float(row.regular_demand)))
                continue
            if pd.isna(row.regular_demand):
                frame.loc[index, 'correction_status'] = 'observed_regular_demand_unknown_or_excluded'
                continue
            if len(history) < min_history:
                frame.loc[index, 'correction_status'] = 'insufficient_history'
                continue
            # Use known available-stock history only. Corrected values never feed history.
            months = np.array([d.year * 12 + d.month for d, _ in history])
            values = np.array([v for _, v in history])
            median = float(np.median(values))
            same_month = [v for d, v in history if d.month == row.date.month]
            seasonal = 1.0
            if len(history) >= 24 and len(same_month) >= 2 and median > 0:
                seasonal = float(np.clip(np.median(same_month) / median, 0.5, 2.0))
            # Robust pairwise slope over the most recent twelve usable observations.
            x, y = months[-12:], values[-12:]
            slopes = [(y[j] - y[i]) / (x[j] - x[i]) for i in range(len(x)) for j in range(i + 1, len(x))]
            slope = float(np.clip(np.median(slopes), -0.1 * median, 0.1 * median))
            target = row.date.year * 12 + row.date.month
            trend_baseline = float(np.median(y - slope * x) + slope * target)
            baseline = max(0.0, min(trend_baseline * seasonal, 3 * median))
            lost = max(0.0, baseline - float(row.regular_demand))
            frame.loc[index, ['latent_demand_baseline', 'seasonality_factor', 'trend_per_month',
                              'estimated_lost_demand', 'corrected_demand', 'correction_status']] = [
                baseline, seasonal, slope, lost, row.regular_demand + lost,
                'historical_stockout_correction' if lost > 0 else 'history_does_not_support_uplift']
    return frame
