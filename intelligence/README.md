# Dataset preprocessing

Includes dataset preprocessing, validated forecasting/procurement, and optional OpenAI agent integration.

From the repository root:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install -r intelligence/requirements.txt
.venv/bin/python -m intelligence
```

Optional paths: `--raw-dir PATH --output-dir PATH`. The loader expects the six supplied workbook filenames and validates their actual sheet headers. It fails on missing files, ambiguous tables, duplicate column labels, or an unexpected monthly schema. Output cannot be written inside the raw directory.

The CLI prints transaction and SKU counts, transaction date range, warehouse count, per-dataset missing values, exact duplicate counts, and duplicate key counts. Duplicate key counts identify candidates for review, not necessarily erroneous transactions.

Outputs in `data/processed/`:

- `transactions.csv`: parsed dates, string SKU codes, original quantity/date values, warehouse and document fields, movement type, and outgoing demand.
- `monthly_sales.csv`: `sku`, `date`, `demand`, and source row.
- `monthly_inventory.csv`: `sku`, `date`, `stock`, and source row.
- `moq.csv`: per-SKU `order_multiple` from the separate MOQ workbook, plus source metadata. `Кратность` does not establish a separate minimum order quantity.
- `goods_in_transit.csv`: normalized product-code, article, category, and `in_transit_quantity` fields; all other columns are retained under their source labels. Unnamed columns use positional `source_column_N` labels. `СЭ в пути 24.09` does not supply an unambiguous arrival year, so no arrival date is inferred.
- `seasonality.csv`: aggregate `date` and `value`, with no inferred SKU or units. Missing October–December 2026 values remain missing.
- `schema_report.json`: every sheet's dimensions, initial rows, formula counts, Excel errors, and source SHA-256 hashes.
- `quality_report.json`: quality statistics, parsing failures, excluded layout rows, sign counts, and SKU coverage differences.

Source row numbers are one-based Excel row numbers; the quality report identifies the corresponding workbook and sheet. Raw files are only opened for reading.

Blank cells stay missing. Only fully blank rows, explicit total rows, and recognized secondary headers are excluded from product tables. Records with missing SKU codes remain visible. Duplicates are retained. Malformed numeric/date values become missing and are counted in the report; source workbooks remain available for review.

Outgoing negative transaction quantities become positive demand. Positive transaction movements retain their quantities and have missing demand rather than being treated as sales. Zero source quantities remain zero. Monthly sales already contain signed net values: negatives are preserved and counted, not converted with absolute value. Monthly dates represent the first day of each month.

SKU normalization trims whitespace and preserves leading zeros and suffixes. Numeric codes become strings; simple Excel zero-padding formats are respected. No article-based matching or guessed SKU corrections are performed. Cross-dataset coverage gaps appear in the quality report.

Excel formulas use cached values; the pipeline does not calculate formulas. An absent cache stays missing. Auxiliary sheets are inspected, but are not combined with the main product tables. Seasonality uses the source year/month aggregate block, not the derived coefficient blocks.

CSV missing values are empty fields. To reload without interpreting identifier strings as null tokens or numbers:

```python
import pandas as pd

sales = pd.read_csv(
    "intelligence/data/processed/monthly_sales.csv",
    dtype={"sku": "string"},
    keep_default_na=False,
    na_values=[""],
    parse_dates=["date"],
)
```

Generated datasets and the local virtual environment are ignored by Git. Rerunning the CLI replaces generated outputs.

## Demand foundation and empirical sign investigation

Run after preprocessing:

```bash
.venv/bin/python -m intelligence.demand
.venv/bin/python -m unittest discover -s intelligence/tests -v
```

The actual transaction workbook does **not** establish a single reliable sales/return sign convention. Neither positive-only, negative-only, signed-net, nor absolute quantities reproduce official monthly sales. The new demand pipeline therefore uses **official monthly sales as observed demand**, preserving negative net corrections. The old `transactions.csv` `demand` and `movement_type` columns reflect the initial provisional convention; **do not use them as verified demand**. `transaction_semantics_audit.csv` explicitly supersedes those interpretations, retaining every transaction and marking its semantics unresolved.

`reconciliation.py` compares only matching SKU/month groups. It reports all-pair and complete-case metrics, sign/year distributions, and document types. A transaction month containing any missing quantity is incomplete, not a partial sum presented as a total. Missing reference values are never filled. Full-set MAE/RMSE/correlation/total differences are undefined in the presence of unknown values; full-set match rates are lower bounds. Near-match tolerance is one source unit. Complete-case metrics exclude both missing reference values and incomplete transaction groups.

`demand.py` creates `demand.csv` with SKU, month-start date, year/month, observed demand, source and correction flag. Negative monthly net values are correction-like observations; they do not identify individual return documents. `corrections_audit.csv` preserves them, while `transaction_semantics_audit.csv` preserves every signed transaction independently.

`outliers.py` uses only prior SKU history: at least six nonnegative, unflagged monthly observations within twelve calendar months. A candidate exceeds all three thresholds: median plus six robust scale units, the upper quartile plus three IQRs, and three times the median. Scale is the maximum of 1.4826 × MAD, IQR/1.349, and one quantity unit. Thresholds, historical sample counts, scores, and exclusions are saved in `regular_demand.csv` and `bulk_outliers_audit.csv`.

Because the authoritative source is monthly, these flags identify **abnormal monthly periods, not confirmed one-time orders**. Seasonal peaks or persistent demand changes can also trigger flags and require review. Customer IDs do not exist in the supplied transaction schema. Flagged periods have missing regular demand, preventing them from inflating a future training series without inventing baseline quantities. Original observed values remain untouched. Negative correction periods also remain auditable and are excluded from regular demand; unknown observations stay unknown.

`stockout.py` returns nullable stockout flags: observed stock <= 0 is evidence of stockout, observed positive stock is false, and missing stock is unknown. Monthly snapshots cannot prove availability throughout a month. The real data contain no observed stockout periods, so no real lost-demand correction is justified.

`lost_demand.py` uses strictly earlier regular demand from known available-stock periods. With at least six observations it estimates a robust historical baseline, a bounded median pairwise trend, and (with 24 observations and two prior matching calendar months) a dimensionless within-SKU seasonal factor. Sparse seasonality uses factor 1. Aggregate seasonality values have unspecified units and are not mixed into SKU quantities. Trend is capped at 10% of the historical median per month; baseline is capped at three times the historical median. These are transparent heuristic estimates, not a trained forecasting model.

Only a known stockout with known regular demand and sufficient history can receive an uplift. `estimated_lost_demand = max(0, baseline - regular_demand)` and `corrected_demand = regular_demand + estimated_lost_demand`. Missing inventory, insufficient history, or excluded/missing demand never creates an uplift. Unknown lost demand remains missing. Previously corrected demand is never fed back into the historical baseline. Every output includes a correction status and available baseline evidence.

Additional outputs in `data/processed/`: `transaction_reconciliation.csv`, `transaction_sign_samples.csv`, `sign_reconciliation_report.json`, `stockouts.csv`, `corrected_demand.csv`, and `demand_summary.json`. The demand pipeline verifies raw-workbook hashes before and after processing. It does not overwrite the six original normalized source datasets.

## Forecasting and quantity-based procurement

Install the updated requirements, then run:

```bash
.venv/bin/python -m pip install -r intelligence/requirements.txt
.venv/bin/python -m intelligence.forecast
.venv/bin/python -m unittest discover -s intelligence/tests -v
```

`features.py` reindexes each SKU onto a monthly calendar before calculating lags. All rolling statistics operate on `regular_demand.shift(1)`; calendar seasonality uses only the known month number. Growth features compare earlier months, never the target. Unknown demand and excluded bulk/correction periods remain NaN. Rolling means require one available historical observation; standard deviations require two. `order_multiple` is deliberately excluded from model predictors because the current MOQ workbook provides no historical effective dates.

The latest workbook month is conservatively treated as partial when the detailed extract ends before month-end within that same month. For the supplied data this excludes September 2026 targets and demand predictors; observations remain visible as `source_regular_demand` and `observed_demand`. October forecasts therefore lack a September lag. This is recorded in every forecast and is a limitation relative to the one-month walk-forward validation.

Eligibility requires six prior nonmissing, nonnegative regular-demand months and an observation within the previous six months. The modeling table and a forecast eligibility file include counts and last usable dates. Initial history builds features; only eligible known targets train the model.

`baseline.py` implements previous-month, previous-year, and three-month rolling mean forecasts. For fair comparison over the same validation rows, an unavailable primary prediction falls back to the past three-month mean, then the historical mean. Fallback counts are explicit. The metrics JSON also includes native baseline scores on available support and all models on common native support. Scores must be compared with their sample counts. WAPE is undefined when aggregate actual demand is zero; MAPE is not used.

`forecast.py` fits six expanding-window CPU CatBoost models for March–August 2026 validation, with no random splitting or validation-driven early stopping. Parameters are fixed: 300 trees, depth 6, learning rate 0.05, RMSE loss, seed 42, two CPU threads. SKU is categorical; numerical missing features use CatBoost's native NaN handling. All predictions are clipped at zero. Reports contain overall, monthly, and descriptive per-SKU metrics (at least three validation observations). These six folds also select the production method by WAPE, so the result is model-selection performance, not an untouched final test result.

A final CatBoost model is fitted on all eligible completed-month targets and saved even if a baseline wins. Production forecasts use the validation winner and explicitly record `effective_model_name` when falling back. `trend` is the most recent available historical one-month growth, with its feature date; it is not a confidence interval.

`reorder.py` joins by SKU only. It uses the latest known inventory balance and retains its month; a balance older than the current source snapshot month requires review. A missing transit SKU is unknown, not zero. `Кратность` in the actual workbook is treated as `order_multiple`, and no independent minimum-order quantity is supplied. Missing/invalid inputs block calculated recommendations and produce explicit review reasons.

The quantity-only scenario is:

```text
available_stock = current_stock + in_transit
net_requirement = max(0, forecast_demand - available_stock)
recommended_quantity = ceil(net_requirement / order_multiple) * order_multiple
```

Urgency is deterministic: `review_required` for unusable inputs; `covered` for zero recommended quantity; `stockout` for positive recommended quantity with observed stock zero; otherwise `replenish`. There is no invented lead time, safety stock, or delivery date. Transit timing is unverified, so these are reviewable one-month quantity scenarios, not purchase orders or guarantees of timely replenishment.

Outputs:

- `models/catboost.cbm` and `models/feature_schema.json` (including parameters and source hashes).
- `data/processed/modeling_dataset.csv` and `forecast_eligibility.csv`.
- `validation_predictions.csv` and `forecast_metrics.json`.
- `forecasts.csv`, including selected/effective method, historical diagnostics, and excluded bulk quantity.
- `procurement_recommendations.csv`, including available stock, net requirement, order multiples, deterministic urgency, JSON explanation components, and counterfactual orders without transit.

Generated model files are ignored by Git. The pipeline verifies unchanged raw-workbook hashes and does not modify the source preprocessing or demand-cleaning outputs. The API and optional LLM integration are documented below; hardware integration is not included.

## FastAPI and Agentic AI integration

Architecture:

```text
Validated CSV/JSON artifacts → immutable service snapshot → deterministic tools
                                    ↓                         ↓
                               FastAPI endpoints       OpenAI tool selection
                                                              ↓
                                                allowlisted tool execution
                                                              ↓
                                             server-rendered verified answer
```

The validated forecasting, cleaning, and reorder algorithms are unchanged. The API exposes saved results. `POST /recalculate` invokes the existing `reorder.recommend` function on saved forecasts and source inputs; it does not retrain, accept numerical overrides, write artifacts, or submit orders. The service holds one consistent snapshot; restart it after externally regenerating pipeline outputs.

Start locally:

```bash
.venv/bin/python -m pip install -r intelligence/requirements.txt
.venv/bin/python -m uvicorn intelligence.api.main:app --host 127.0.0.1 --port 8000
```

Interactive schemas are available at `http://127.0.0.1:8000/docs`. This local service has no public authentication layer; keep it bound to loopback or behind an authenticated application gateway.

| Endpoint | Result |
|---|---|
| `GET /health` | Status, selected forecasting method, forecast date/count, replenishment/review counts, and whether an agent key is configured |
| `GET /forecast/{sku}` | Typed saved forecast and diagnostics; 404 if unavailable |
| `GET /recommendation/{sku}` | Typed saved recommendation, explicit nulls, and explanation components |
| `GET /recommendations` | All recommendations, with `total` and `items` |
| `GET /recommendations?status=replenishment` | Positive order quantities |
| `GET /recommendations?status=review_required` | Missing/invalid input review queue |
| `GET /recommendations?status=transit_affected` | Orders reduced by goods in transit |
| `POST /recalculate` | Existing deterministic reorder calculation for `{}` (all SKUs) or `{"sku":"030200203_"}` |
| `POST /agent/run` | Procurement tool orchestration; 503 with a structured response if no key/provider is available |

Environment variables:

- `OPENAI_API_KEY`: optional; read exclusively from the process environment. Never included in responses, logs, or examples. `.env.example` contains a blank placeholder. No `.env` file is loaded automatically; inject the key through your environment or secret manager.
- `OPENAI_MODEL`: optional Responses API model, default `gpt-4.1-mini`. Account/model access must be available when enabling the agent.

All deterministic endpoints work without an OpenAI key. Health's `agent_available` indicates configuration, not a successful provider connectivity check. Missing artifacts produce a degraded health response and 503 on data endpoints.

Examples:

```bash
curl http://127.0.0.1:8000/health
curl http://127.0.0.1:8000/forecast/030200203_
curl http://127.0.0.1:8000/recommendation/030200203_
curl 'http://127.0.0.1:8000/recommendations?status=replenishment'
curl 'http://127.0.0.1:8000/recommendations?status=review_required'
curl -X POST http://127.0.0.1:8000/recalculate \
  -H 'Content-Type: application/json' -d '{"sku":"030200203_"}'
curl -X POST http://127.0.0.1:8000/agent/run \
  -H 'Content-Type: application/json' \
  -d '{"message":"Why should we order SKU 030200203_?"}'
```

The OpenAI integration follows the official [Responses API function-calling flow](https://developers.openai.com/api/docs/guides/function-calling): send strict function schemas, execute returned calls on the server, return outputs associated with each `call_id`, and preserve response items across rounds. Requests use `store=False`, a fixed official API base URL, bounded timeouts, at most six rounds, and at most twelve executed/attempted calls. With a configured key, user requests and selected tool results are transmitted to OpenAI; no environment variables or credentials are included in tool results.

Tools: `get_forecast`, `get_inventory`, `get_in_transit`, `get_order_multiple`, `get_bulk_adjustments`, `get_recommendation`, `list_replenishment_recommendations`, `list_review_required`, `calculate_reorder`, and `list_transit_affected`. SKU tools accept only a SKU string; list tools accept no arguments. No shell, SQL, path, arbitrary quantity, supplier-submission, or approval tool exists.

The LLM chooses the evidence to retrieve. **Public business facts and explanation text are rendered from the deterministic tool outputs**, rather than trusting generated numerical prose. Free-form model responses cannot overwrite quantities or grant approval. This intentionally limits narrative flexibility to prevent unsupported claims. Missing values serialize as JSON `null`, never NaN or invented zero. Missing supplier terms are explicitly identified. `requires_human_review` is always true because even complete recommendations require human purchase approval.

Agent response shape:

```json
{
  "answer": "SKU 030200203_: recommended quantity 900; ... Human approval is mandatory.",
  "tools_used": ["get_recommendation"],
  "data": {"status": "ok", "results": [{"tool": "get_recommendation", "result": {}}]},
  "requires_human_review": true
}
```

The actual `result` contains the complete authoritative tool payload. Unknown tools/extra arguments are rejected. Provider errors are sanitized; there is no raw exception or credential echo. The missing-key response is HTTP 503 with `data.status="agent_unavailable"` and an empty `tools_used` list.

Validation:

```bash
.venv/bin/python -m unittest discover -s intelligence/tests -v
```

The complete suite has 38 tests, including the prior 22 algorithm tests plus API, recalculation, null-handling, missing-key, bounded-tool, and adversarial model-output tests. `data/processed/api_demo.json` records live localhost endpoint results and a **separately labeled simulated** agent tool trace. With no API key configured, the live agent endpoint invokes no tools and sends no provider request. No live paid model call was performed during this validation. Raw workbook hashes were verified unchanged. Jetson/RealSense remains outside this integration.
