"""Conservative, causal bulk-period candidates, not confirmed customer orders."""
from dataclasses import dataclass, asdict
import numpy as np
import pandas as pd


@dataclass(frozen=True)
class BulkConfig:
    min_history: int = 6
    window_months: int = 12
    robust_z_threshold: float = 6.0
    iqr_multiplier: float = 3.0
    median_multiplier: float = 3.0


def detect_bulk_outliers(demand, config=BulkConfig()):
    """Use strictly prior, nonnegative, non-outlier monthly observations per SKU.

    A flagged month's regular demand is NA, excluding it rather than inventing
    baseline sales within that month. Its entire observed value remains auditable.
    Negative net corrections remain observed but are excluded from regular demand.
    """
    if config.min_history < 2 or config.window_months < 1:
        raise ValueError('History and window parameters must be positive and meaningful')
    result = demand.copy()
    result['date'] = pd.to_datetime(result.date)
    result = result.sort_values(['sku', 'date']).reset_index(drop=True)
    if result.duplicated(['sku', 'date']).any():
        raise ValueError('Bulk detection expects one observation per SKU/month')
    result['regular_demand'] = result.observed_demand.where(result.observed_demand >= 0)
    result['is_bulk_outlier'] = False
    for column in ('outlier_score', 'history_median', 'history_mad', 'history_iqr', 'bulk_threshold'):
        result[column] = np.nan
    result['history_count'] = 0
    for _, group in result.groupby('sku', sort=False):
        history = []
        for index, row in group.iterrows():
            cutoff = row.date - pd.DateOffset(months=config.window_months)
            history = [(d, q) for d, q in history if d >= cutoff]
            values = np.array([q for _, q in history], dtype=float)
            result.loc[index, 'history_count'] = len(values)
            value = row.observed_demand
            if pd.isna(value) or value < 0:
                continue
            flagged = False
            if len(values) >= config.min_history:
                median = float(np.median(values))
                mad = float(np.median(np.abs(values - median)))
                q1, q3 = np.quantile(values, [0.25, 0.75])
                iqr = float(q3 - q1)
                # A one-unit floor handles constant/zero history without infinite scores.
                scale = max(1.4826 * mad, iqr / 1.349, 1.0)
                score = (value - median) / scale
                threshold = max(median + config.robust_z_threshold * scale,
                                q3 + config.iqr_multiplier * iqr,
                                config.median_multiplier * median)
                flagged = bool(value > threshold)
                result.loc[index, ['outlier_score', 'history_median', 'history_mad',
                                   'history_iqr', 'bulk_threshold']] = [score, median, mad, iqr, threshold]
            if flagged:
                result.loc[index, 'is_bulk_outlier'] = True
                result.loc[index, 'regular_demand'] = np.nan
            else:
                history.append((row.date, float(value)))
    result['exclusion_reason'] = pd.NA
    result.loc[result.observed_demand < 0, 'exclusion_reason'] = 'negative_net_correction'
    result.loc[result.is_bulk_outlier, 'exclusion_reason'] = 'bulk_period_candidate'
    result.attrs['config'] = asdict(config)
    return result
