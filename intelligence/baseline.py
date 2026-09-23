"""Transparent baselines and comparable, missing-aware evaluation."""
import numpy as np
import pandas as pd

BASELINES = {'previous_month': 'lag_1', 'previous_year': 'lag_12', 'rolling_3': 'rolling_mean_3',
             'seasonal_trend': 'seasonal_trend_prediction'}


def select_models(validation, default_model, min_months=4):
    """Per-SKU choice from chronological backtests, with a global sparse-data fallback."""
    result = {}
    for sku, rows in validation.groupby('sku'):
        candidates = []
        for name, group in rows.groupby('model_name'):
            score = metrics(group.actual, group.prediction)
            if score['n'] >= min_months:
                candidates.append((score['mae'], name != default_model, name))
        result[sku] = min(candidates)[2] if candidates else default_model
    return result


def nonnegative(values):
    return np.maximum(np.asarray(values, dtype=float), 0)


def predict_baseline(features, name):
    native = features[BASELINES[name]].copy()
    # Sparse observations stay missing in features. Forecast fallback is explicit.
    prediction = native.fillna(features.rolling_mean_3).fillna(features.history_mean)
    return nonnegative(prediction), native.isna()


def metrics(actual, predicted):
    actual, predicted = np.asarray(actual, dtype=float), np.asarray(predicted, dtype=float)
    valid = np.isfinite(actual) & np.isfinite(predicted)
    y, p = actual[valid], predicted[valid]
    if not len(y):
        return {'n': 0, 'mae': None, 'rmse': None, 'wape_pct': None}
    error = p - y
    return {'n': len(y), 'mae': float(np.abs(error).mean()),
            'rmse': float(np.sqrt(np.mean(error ** 2))),
            'wape_pct': float(100 * np.abs(error).sum() / np.abs(y).sum()) if np.abs(y).sum() else None}


def evaluate(predictions):
    result = {}
    for model, group in predictions.groupby('model_name'):
        per_sku = {sku: metrics(g.actual, g.prediction) for sku, g in group.groupby('sku') if len(g) >= 3}
        result[model] = {'overall': metrics(group.actual, group.prediction),
                         'by_month': {str(date): metrics(g.actual, g.prediction) for date, g in group.groupby('date')},
                         'per_sku_min_3_months': per_sku,
                         'fallback_predictions': int(group.fallback_used.sum())}
    return result
