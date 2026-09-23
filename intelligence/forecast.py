"""CPU CatBoost, chronological backtests, next-month forecast and procurement.

Run: python -m intelligence.forecast
"""
import hashlib
import json
from pathlib import Path

import numpy as np
import pandas as pd
from catboost import CatBoostRegressor

from .loader import DATA_DIR, FILES
from .features import build_features, eligibility, FEATURES
from .baseline import BASELINES, predict_baseline, nonnegative, evaluate, metrics
from .reorder import recommend

MODEL_DIR = Path(__file__).resolve().parent / 'models'
PARAMETERS = dict(iterations=300, depth=6, learning_rate=0.05, loss_function='RMSE',
                  random_seed=42, thread_count=2, task_type='CPU', verbose=False, allow_writing_files=False)


def fit_model(training):
    model = CatBoostRegressor(**PARAMETERS)
    model.fit(training[FEATURES], training.regular_demand, cat_features=['sku'])
    return model


def read(name):
    return pd.read_csv(DATA_DIR / 'processed' / f'{name}.csv', dtype={'sku': 'string'},
                       keep_default_na=False, na_values=[''])


def main():
    output = DATA_DIR / 'processed'
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    raw_hashes = {name: hashlib.sha256((DATA_DIR / 'raw' / name).read_bytes()).hexdigest() for name in FILES.values()}
    source = read('regular_demand')
    source['date'] = pd.to_datetime(source.date)
    # Source extract ends 22 September; September totals are not complete-month targets.
    last_source_month = source.date.max()
    tx = read('transactions')
    extract_date = pd.to_datetime(tx.date).max()
    partial_last_month = extract_date.to_period('M') == last_source_month.to_period('M') and not extract_date.is_month_end
    source['is_partial_month'] = partial_last_month & source.date.eq(last_source_month)
    source['source_regular_demand'] = source.regular_demand
    source.loc[source.is_partial_month, 'regular_demand'] = np.nan
    last_complete_month = last_source_month - pd.offsets.MonthBegin(1) if partial_last_month else last_source_month
    forecast_date = last_source_month + pd.offsets.MonthBegin(1)
    future = pd.DataFrame({'sku': sorted(source.sku.unique()), 'date': forecast_date,
                           'observed_demand': np.nan, 'regular_demand': np.nan})
    features = build_features(pd.concat([source, future], ignore_index=True))
    features['eligible'] = eligibility(features)
    features.to_csv(output / 'modeling_dataset.csv', index=False)
    validation_months = pd.date_range(end=last_complete_month, periods=6, freq='MS')
    predictions, folds = [], []
    for month in validation_months:
        train = features[(features.date < month) & features.regular_demand.notna() & features.eligible]
        val = features[(features.date == month) & features.regular_demand.notna() & features.eligible]
        if train.empty or val.empty:
            raise ValueError(f'Insufficient training/validation data for {month}')
        model = fit_model(train)
        folds.append({'validation_month': month.isoformat(), 'train_start': train.date.min().isoformat(),
                      'train_end': train.date.max().isoformat(), 'train_rows': len(train), 'validation_rows': len(val)})
        for name in [*BASELINES, 'catboost']:
            if name == 'catboost':
                values, fallback = nonnegative(model.predict(val[FEATURES])), np.zeros(len(val), dtype=bool)
            else:
                values, fallback = predict_baseline(val, name)
            predictions.append(pd.DataFrame({'sku': val.sku.values, 'date': month,
                                              'actual': val.regular_demand.values, 'prediction': values,
                                              'model_name': name, 'fallback_used': np.asarray(fallback)}))
        print(f'Validated {month:%Y-%m}: {len(train)} training rows; {len(val)} targets', flush=True)
    validation = pd.concat(predictions, ignore_index=True)
    validation.to_csv(output / 'validation_predictions.csv', index=False)
    scores = evaluate(validation)
    # Also expose pure baseline metrics without fallbacks and common native support.
    joined = validation.merge(features[['sku', 'date', *set(BASELINES.values())]],
                               on=['sku', 'date'], how='left', validate='many_to_one')
    native_metrics = {}
    for name, column in BASELINES.items():
        rows = joined[joined.model_name == name]
        native_metrics[name] = metrics(rows.actual, rows[column])
    common = joined[list(BASELINES.values())].notna().all(axis=1)
    common_native_metrics = {}
    for name, rows in joined[common].groupby('model_name'):
        prediction = rows[BASELINES[name]] if name in BASELINES else rows.prediction
        common_native_metrics[name] = metrics(rows.actual, prediction)
    best_baseline = min(BASELINES, key=lambda k: scores[k]['overall']['wape_pct'])
    selected = min(scores, key=lambda k: scores[k]['overall']['wape_pct'])
    train = features[(features.date <= last_complete_month) & features.regular_demand.notna() & features.eligible]
    final_model = fit_model(train)
    final_model.save_model(str(MODEL_DIR / 'catboost.cbm'))
    future_features = features[(features.date == forecast_date) & features.eligible].copy()
    if selected == 'catboost':
        forecast_values = nonnegative(final_model.predict(future_features[FEATURES]))
        fallback = np.zeros(len(future_features), dtype=bool)
    else:
        forecast_values, fallback = predict_baseline(future_features, selected)
    forecast = pd.DataFrame({'sku': future_features.sku.values, 'forecast_date': forecast_date,
                             'forecast_demand': forecast_values, 'model_name': selected,
                             'rolling_mean_3': future_features.rolling_mean_3.values,
                             'trend': future_features.growth_1.values, 'fallback_used': np.asarray(fallback)})
    last = source[source.observed_demand.notna()].sort_values('date').drop_duplicates('sku', keep='last')
    forecast = forecast.merge(last[['sku', 'date', 'observed_demand']].rename(columns={
        'date': 'last_observed_date', 'observed_demand': 'last_observed_demand'}), on='sku', how='left', validate='one_to_one')
    excluded = source.assign(excluded=source.observed_demand.where(source.is_bulk_outlier.eq(True), 0)).groupby('sku').excluded.sum()
    forecast['bulk_quantity_excluded'] = forecast.sku.map(excluded)
    if selected in BASELINES:
        native_missing = future_features[BASELINES[selected]].isna().to_numpy()
        rolling_available = future_features.rolling_mean_3.notna().to_numpy()
        forecast['effective_model_name'] = np.where(native_missing,
            np.where(rolling_available, 'rolling_3_fallback', 'historical_mean_fallback'), selected)
    else:
        forecast['effective_model_name'] = selected
    recent_trend = features[(features.date <= forecast_date) & features.growth_1.notna()].sort_values('date').drop_duplicates('sku', keep='last')
    forecast['trend'] = forecast.sku.map(recent_trend.set_index('sku').growth_1)
    forecast['trend_feature_date'] = forecast.sku.map(recent_trend.set_index('sku').date)
    forecast['partial_latest_month_excluded'] = bool(partial_last_month)
    forecast.to_csv(output / 'forecasts.csv', index=False)
    inventory, transit, multiples = read('monthly_inventory'), read('goods_in_transit'), read('moq')
    procurement = recommend(forecast, inventory, transit, multiples, last_source_month)
    procurement.to_csv(output / 'procurement_recommendations.csv', index=False)
    features.loc[features.date == forecast_date, ['sku', 'date', 'history_count', 'last_history_date', 'eligible']].to_csv(output / 'forecast_eligibility.csv', index=False)
    schema = {'features': FEATURES, 'categorical_features': ['sku'], 'target': 'regular_demand',
              'parameters': PARAMETERS, 'missing_features': 'CatBoost native numeric NaN; no zero imputation',
              'order_multiple': 'excluded from predictors: no historically effective dates; used only for current procurement',
              'selected_model': selected, 'baseline_fallback': 'native predictor, then past rolling_mean_3, then past history_mean',
              'eligibility': 'at least six prior usable months; last usable demand within six months',
              'growth_1': '(lag_1-lag_2)/lag_2 when lag_2>0',
              'growth_3': '(lag_1-lag_4)/lag_4 when lag_4>0',
              'raw_sha256': raw_hashes}
    (MODEL_DIR / 'feature_schema.json').write_text(json.dumps(schema, indent=2, ensure_ascii=False)+'\n')
    report = {'metrics': scores, 'native_baseline_metrics': native_metrics,
              'common_native_support_metrics': common_native_metrics, 'folds': folds, 'selected_model': selected, 'best_baseline': best_baseline,
              'catboost_beats_best_baseline_wape': scores['catboost']['overall']['wape_pct'] < scores[best_baseline]['overall']['wape_pct'],
              'history_start': source.date.min().isoformat(), 'final_training_start': train.date.min().isoformat(),
              'final_training_end': train.date.max().isoformat(), 'final_training_rows': len(train),
              'validation_start': validation_months.min().isoformat(), 'validation_end': validation_months.max().isoformat(),
              'forecast_date': forecast_date.isoformat(), 'eligible_skus': len(forecast),
              'excluded_skus': int(source.sku.nunique()) - len(forecast),
              'forecast_effective_methods': forecast.effective_model_name.value_counts().to_dict(),
              'latest_month_excluded_as_partial': bool(partial_last_month),
              'recommendations_requiring_replenishment': int((procurement.recommended_quantity > 0).sum()),
              'total_recommended_units': float(procurement.recommended_quantity.sum(min_count=1)),
              'recommendations_needing_input_review': int(procurement.recommended_quantity.isna().sum()),
              'recommendations_reduced_by_transit': int(procurement.transit_reduced_order.sum()),
              'recommendations_increased_by_multiple_rounding': int(procurement.rounding_increased_order.sum()),
              'raw_files_unchanged': all(hashlib.sha256((DATA_DIR / 'raw' / n).read_bytes()).hexdigest() == h for n,h in raw_hashes.items()),
              'limitations': ['Six expanding-window one-month backtests; model selection and metrics share these folds, no untouched final test set.',
                              'Per-SKU metrics require three validation targets and remain descriptive with only six folds.',
                              'Source latest month conservatively excluded as partial, based on extract date; no daily completeness adjustment invented.',
                              'October forecast has no September demand feature because September is partial; validation uses one-month origins.',
                              'Bulk and negative-net periods excluded from target; metrics evaluate regular demand only.',
                              'No calibrated confidence intervals.',
                              'Procurement is a quantity-only scenario with unverified transit arrival dates, no supplier lead times or safety stock.',
                              'Missing transit, invalid order multiples, and missing/stale stock block calculated recommendations.']}
    (output / 'forecast_metrics.json').write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n')
    if not report['raw_files_unchanged']:
        raise RuntimeError('Raw workbook changed')
    print(json.dumps({k:v for k,v in report.items() if k not in ('metrics','folds','limitations')}, indent=2))
    print(json.dumps({k:v['overall'] for k,v in scores.items()}, indent=2))


if __name__ == '__main__':
    main()
