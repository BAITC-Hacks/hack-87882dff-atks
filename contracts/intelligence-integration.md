# Intelligence Service integration

Target: Web/Mobile -> PHP -> FastAPI -> forecasting/reorder/OpenAI.
PHP owns MySQL, inventory transactions, authentication and human order approval.
The Intelligence API owns numbers, anomaly detection and agent orchestration.

## Available PHP transport

Set `INTELLIGENCE_API_URL` in the PHP server environment or root `.env` to the
actual service base URL. The imported local service is `http://127.0.0.1:8004`.
Never use the OpenAI API URL or its API key here. Windows startup:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/start-intelligence.ps1
```

Dependencies live in `.venv-intelligence` (`intelligence/requirements.txt`).
The script disables OpenAI by default; explicit `-EnableAgent` requires a new
key already in the Python process environment. No PHP `.env` file is loaded.
The archive's environment templates were excluded. Revoke exposed keys.

## Shared Read-Only Snapshot

`GET /api/v1/intelligence/results[?refresh=1]` is available to authenticated
managers AND warehouse staff. It wraps the Python recommendation list:

```json
{
  "success": true,
  "data": {
    "source": "intelligence",
    "results": { "total": 0, "items": [] },
    "health": null,
    "health_error": null,
    "fetched_at": "2026-09-23T00:00:00Z",
    "cached": false,
    "stale": false,
    "error": null,
    "read_only": true,
    "live_inventory_sync": false
  }
}
```

`IntelligenceResultsService` validates count, SKU uniqueness, types and nulls
before persisting the full response in `intelligencecache`. Run `php setup.php`
to add this separate table without deleting existing data. Reads share a
60-second cache keyed by service URL with an independent MySQL refresh lock.
Timestamps use UTC. On refresh failure the last valid response stays available
with an explicit stale/error flag. No snapshot means an error, not demo data.
Health failure is independent of numeric recommendation availability.
Force refresh fetches results; it does not train a model or call `/recalculate`.

Web dashboard/forecast/procurement and the mobile `Прогнозы` tab use this snapshot,
with SKU search, replenishment/review/all filters and per-SKU explanation details.
The recalculated dataset currently has 422 forecasts, 21 replenishment and 25 review
rows. UI counts are derived from API items, not hardcoded.

The all-status recommendation response now also carries an optional `catalog`
array of `{sku, product_name, article, unit, has_forecast}`. Labels are joined
by exact SKU from MOQ/transit, transaction labels and the original inventory
workbook, without importing or changing any balances. The combined catalog has
724 SKUs; 302 do not have forecasts. Recommendation `total` remains 422 and
catalog enrichment does not change quantities. Old upstream responses without a catalog
remain supported. Both clients default to all products, search names/articles/
codes, and explicitly distinguish missing forecasts from missing order inputs.
Unknown units stay null. Branding is Qoyma; transport route names are unchanged.

## Manager-Only Proxy

`IntelligenceApiService` centralizes the documented read endpoints and agent POST.
Manager bearer authentication is required on these additional proxy endpoints:

| PHP endpoint | FastAPI endpoint |
| --- | --- |
| GET /api/v1/intelligence/health | GET /health |
| GET /api/v1/intelligence/schema | GET /openapi.json |
| GET /api/v1/intelligence/forecast?sku=... | GET /forecast/{sku} |
| GET /api/v1/intelligence/recommendation?sku=... | GET /recommendation/{sku} |
| GET /api/v1/intelligence/recommendations | GET /recommendations |
| GET /api/v1/intelligence/recommendations?status=review_required | GET /recommendations?status=review_required |
| POST /api/v1/intelligence/agent/run | POST /agent/run |

Agent POST accepts exactly `{"message":"..."}` as verified from actual Swagger
and the imported Python source (1-4000 characters, no extra fields). The web
agent form is connected to this endpoint. Health is checked first, and ordinary
GET requests never invoke OpenAI. `agent_available` means analysis is available;
`metadata.agent_mode` distinguishes local fact lookup from OpenAI configuration.
Configuration does not verify provider access or billing. Missing keys/provider
failure return a labeled local result (`data.mode=local`, reason provided), not
an imitation of an LLM answer. The dedicated server file `intelligence/.env` is
read on each request; process environment and PHP keys are ignored. The PHP response wraps the upstream
object/list in `{success: true, source: "intelligence", data: ...}`. Decimal
quantities, absent/null values, `review_required`, `tools_used`, and
`requires_human_review` are not replaced with guesses or zeros. Calls cannot
create purchase orders, modify inventory, or approve anything in MySQL.

The PHP client does not read or forward `OPENAI_API_KEY`, browser authorization,
or cookies. GET requests have a 15-second total timeout, health 3, agent POST 25;
the connect timeout is 3 seconds. Redirects and automatic POST retries are off.
Responses are bounded to 8 MiB. Errors are sanitized. An upstream 401/403 becomes
502, not a browser logout. An unavailable agent does not trigger agent calls
from inventory or recommendation GET endpoints.

Read-only connection check: `php tools/check-intelligence.php`.
This checks health and lists OpenAPI paths; it does not invoke OpenAI.

## Deliberate Boundary: No Catalog or Stock Import

The user requested only Python results, not a catalog or historical stock import.
External results come from the archived Systeme Electric CSV/Excel artifacts,
not live MySQL. They are NOT inserted into product/stock/forecast/recommendation/
purchaseorder tables. Only the separate cache is written. Existing warehouse
operations and previously confirmed orders remain available.

Supplier, warehouse, lead time and safety stock are not supplied by this API.
Missing quantities stay null. Monthly bulk exclusion is not a claim of verified
customer-level one-time orders. The imported forecasting implementation is kept.

Direct-PHP OpenAI calls are disabled; historical explanations remain readable.
Any old OpenAI key in PHP `.env` is ignored. Keep a new key only in Python and
revoke previously shared keys. The old `ML_API_URL` / `POST /forecast` schema is
incompatible; do not point that variable at this service.

Before enabling external-result approval or live synchronization, agree on:

- Data revisions and stale-order protection.
- Supplier and warehouse scope; mapping real 1C codes to existing MySQL products.
- Missing inputs and `review_required` semantics, numeric units and multiples.
- Service authentication before deployment outside localhost.
- A real MySQL-to-Python inventory/history update contract.

Then map external results without recomputing quantities in PHP, persist their
provenance and keep manager approval/stale-data protection. No external rows are
silently attached to the demo warehouse or given invented suppliers/lead times.
No quantities such as 422 forecasts or 900 units should be hardcoded in the UI.
Setting `INTELLIGENCE_API_URL` blocks the old local procurement calculation with
an explicit 409 until this mapping is implemented, rather than returning mock
numbers under a Python label. Ordinary warehouse operations remain available.

FastAPI `POST /recalculate` accepts `{}` or `{"sku":"..."}` and recomputes over
the same CSV snapshot. It is not exposed through PHP or called after stock
mutations. Until live synchronization exists, recomputation over unchanged Excel
snapshot must never be labelled synchronized with live warehouse balances.
Hardware integration remains a later, separately authenticated input workflow.
