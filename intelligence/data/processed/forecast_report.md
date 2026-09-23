# Forecasting and procurement validation

22 tests passed; independent real-output checks and saved CatBoost reload passed. Raw workbook hashes unchanged.

Eligible SKUs: 422; excluded: 132. Source history January 2024–August 2026; training targets July 2024–August 2026 (7,313 rows). Six expanding-window validation months: March–August 2026, 1,815 eligible known SKU/month targets. Forecast month: October 2026.

| Method (documented fallbacks) | MAE | RMSE | WAPE |
|---|---:|---:|---:|
| catboost | 134.94 | 829.83 | 25.66% |
| previous_month | 126.64 | 726.76 | 24.08% |
| previous_year | 159.16 | 860.25 | 30.26% |
| rolling_3 | 127.95 | 822.48 | 24.33% |

CatBoost did not beat the best baseline. Previous-month demand with documented fallback won overall WAPE. Because September is conservatively excluded as partial, October uses rolling-history fallback for 381 SKUs and historical-mean fallback for 41. These diagnostics are explicit in forecasts.csv. Native baseline metrics and common-support metrics, all fold dates, and per-month/per-SKU metrics are in forecast_metrics.json.

Calculated replenishment: 23 SKUs, 2,160 total units. Input review required: 25 SKUs. Transit reduced rounded orders for 3 SKUs; order-multiple rounding increased 21 recommendations.

| SKU | Forecast | Stock | Transit | Order multiple | Recommended | Urgency |
|---|---:|---:|---:|---:|---:|---|
| 030200203_ | 4928.0 | 4668.0 | 0.0 | 900.0 | 900.0 | replenish |
| 130300026_ | 464.5 | 247.0 | 0.0 | 140.0 | 280.0 | replenish |
| 130300027_ | 142.0 | 6.0 | 0.0 | 140.0 | 140.0 | replenish |
| 130300028_ | 261.0 | 196.0 | 0.0 | 140.0 | 140.0 | replenish |
| 300200856_ | 496.0 | 364.0 | 0.0 | 5.0 | 135.0 | replenish |
| 300200933_ | 159.5 | 75.0 | 0.0 | 5.0 | 85.0 | replenish |
| 030200193_ | 37745.0 | 27322.0 | 37800.0 | 3780.0 | 0.0 | covered |
| 300200720_ | 37.5 | 20.0 | 80.0 | 5.0 | 0.0 | covered |
| 300200722_ | 34.5 | 9.0 | 40.0 | 5.0 | 0.0 | covered |
| 030200003_ | 7.5 | 4.0 | unknown | 1.0 | unknown | review_required |

## Limitations

- Six expanding-window one-month backtests; model selection and metrics share these folds, no untouched final test set.
- Per-SKU metrics require three validation targets and remain descriptive with only six folds.
- Source latest month conservatively excluded as partial, based on extract date; no daily completeness adjustment invented.
- October forecast has no September demand feature because September is partial; validation uses one-month origins.
- Bulk and negative-net periods excluded from target; metrics evaluate regular demand only.
- No calibrated confidence intervals.
- Procurement is a quantity-only scenario with unverified transit arrival dates, no supplier lead times or safety stock.
- Missing transit, invalid order multiples, and missing/stale stock block calculated recommendations.
