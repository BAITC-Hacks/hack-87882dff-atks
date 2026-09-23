# Real-data demand semantics investigation

No transaction sign convention is selected: none reproduces the official monthly-sales data sufficiently. Official monthly sales are the demand foundation. No forecasting model is trained.

## Transaction signs

| Sign | Count | Total quantity | Document types |
|---|---:|---:|---|
| positive | 76,997 | 6841676.0 | {"Расходная накладная": 76997} |
| negative | 302 | -4198.0 | {"Расходная накладная": 299, "Заказ покупателя": 3} |
| zero | 0 | 0.0 | {} |
| missing | 13 | unknown | {"Расходная накладная": 13} |

## Matched SKU/month reconciliation

There are 7,384 matching SKU/months, including 363 missing reference values. Excluding these and 13 incomplete transaction aggregates leaves 7,008 complete pairs. No missing values are imputed. Near match means absolute error <= 1 unit. Total difference is transaction-derived quantity minus reference.

| Method | MAE | RMSE | Correlation | Exact | Near | Total difference |
|---|---:|---:|---:|---:|---:|---:|
| A: positive_only | 502.63 | 6111.37 | 0.855166 | 38.07% | 43.45% | +3,406,677 |
| B: absolute_negative_only | 460.71 | 3510.40 | -0.006353 | 0.76% | 7.43% | -3,225,216 |
| C: signed_net | 503.21 | 6111.39 | 0.855165 | 38.07% | 43.44% | +3,402,538 |
| D: absolute_all | 502.05 | 6111.35 | 0.855168 | 38.83% | 44.48% | +3,410,816 |

B minimizes MAE/RMSE by predicting almost no demand, but its correlation is approximately zero and its exact match rate is below 1%. D has the strongest correlation and match rates, but overstates the comparable reference total by 3,410,816 units. Neither provides a defensible transaction sign mapping.

Full-set error metrics are undefined because unknown observations cannot be compared. Full-set match rates below count only proven matches, with unknown pairs left unassessable:

| Method | All pairs | Comparable | Exact lower bound | Near lower bound |
|---|---:|---:|---:|---:|
| A | 7384 | 7008 | 36.13% | 41.24% |
| B | 7384 | 7008 | 0.72% | 7.06% |
| C | 7384 | 7008 | 36.13% | 41.22% |
| D | 7384 | 7008 | 36.85% | 42.21% |

## Coverage and document evidence

All 76,997 positive movements use Расходная накладная. Of 302 negatives, 299 use the same type and three use Заказ покупателя. The names alone do not distinguish sales from corrections. Negative movements: six in 2023, 292 in 2024, none in 2025, four in 2026. Positive movements: 45,577 in 2025 and 31,420 in 2026. This is a substantial time-coverage difference, not evidence that every negative is a return.

## Representative samples

| Sign | Date | Document number | Type | SKU | Product | Warehouse | Quantity |
|---|---|---|---|---|---|---|---:|
| positive | 2025-05-12 14:22:36 | 20000046174 | Расходная накладная | 300200323_ | S426 Роз  о/у с з/к 16А 250В ИЗОЛ.ПЛ. "BLANCA" белый BLNRA010111 (72) | Алматы | 20 |
| positive | 2025-11-21 10:42:36 | 20000131626 | Расходная накладная | 300200286_ | S398 Роз. с з/к 16А с/у б/рамки "ATLAS" белый ATN143 (20) | Алматы | 8 |
| positive | 2025-02-05 15:05:09 | 20000011739 | Расходная накладная | 300200821_ | G038 Рамка 1-ная "ARTGALLERY" шампань GAL0501 (20) | Алматы | 6 |
| positive | 2025-08-29 17:40:15 | 20000093572 | Расходная накладная | 300200305_ | S407 Рамка 3-ная "ATLAS" белый (15) ATN103 | Алматы | 20 |
| positive | 2025-01-04 09:23:24 | 20000000026 | Расходная накладная | 300200328_ | S431 Роз 4-ная  о/у с з/к со штор. 16А 250В ИЗОЛ.ПЛ. "BLANCA" белый BLNRA011411 (24) | Алматы | 30 |
| negative | 2024-09-11 17:11:59 | 20000099067 | Расходная накладная | 300200845_ | G062 Роз. TV оконечная механизм "ARTGALLERY" лотос GAL1391 (5) | Алматы | -2 |
| negative | 2024-11-01 17:36:56 | 20000121278 | Расходная накладная | 300200829_ | G046 Роз. 2-ная TV комп. RJ45 5Е "ARTGALLERY" шампань GAL0589 (5) | Алматы | -9 |
| negative | 2024-09-02 10:06:02 | 20000094407 | Расходная накладная | 300200825_ | G042 Рамка 5-ная "ARTGALLERY" шампань GAL0505 (5) | Алматы | -4 |
| negative | 2024-11-01 17:36:56 | 20000121278 | Расходная накладная | 300200929_ | G146 Роз. 2-ная TV комп. RJ45 5Е "ARTGALLERY" мокко GAL0689 (5) | Алматы | -9 |
| negative | 2023-01-18 16:00:11 | 20000004016 | Расходная накладная | 300200445_ | А409 Рамка 5-ная "ATLAS" алюминий ATN305 (5) | Алматы | -10 |
| negative | 2026-06-02 11:47:41 | 00000526019 | Заказ покупателя | 300200296_ | S384 Выкл 1 кл 10А с/у "ATLAS" белый ATN112  (15) | Алматы | -10 |

## Real-data processing results

- Abnormal monthly bulk candidates: 105 across 69 SKUs; 30,111 units excluded from regular demand, retained in audit.
- Negative monthly net corrections: 323 periods totaling -5,192, preserved in observed demand and correction audit.
- Observed stockout periods: 0; missing inventory: 5133 SKU/months overall, 2205 in the sales universe.
- Estimated lost demand: 0; affected SKUs: 0. Unknown inventory remains unestimated, so zero is not proof of no actual lost sales.
- All 11 unit tests passed. Real-output checks confirmed unchanged observed demand, auditable corrections, bulk exclusions, and the correction arithmetic.
- Raw-workbook SHA-256 hashes unchanged. No customer fields invented. No forecasting model trained.

Bulk candidates are monthly anomalies, not confirmed one-time order events. Transaction semantics and the source-scope discrepancy need business/source-system confirmation before transaction-level demand can be used.
