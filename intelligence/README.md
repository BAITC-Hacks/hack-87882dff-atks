# Dataset preprocessing

No model training or forecasting is implemented.

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
