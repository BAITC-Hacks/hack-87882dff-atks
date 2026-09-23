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
