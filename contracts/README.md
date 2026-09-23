# SupplyMind ML Contract 1.0

PHP calls `POST {ML_API_URL}/forecast` with JSON:

```json
{
  "contract_version": "1.0",
  "items": [{
    "product_id": 6,
    "warehouse_id": 1,
    "sku": "SM-DEMO-OAT-06",
    "product_name": "Oat flakes",
    "warehouse_name": "Central warehouse",
    "current_stock": 12,
    "stock_version": 1,
    "in_transit": 0,
    "lead_time_days": 4,
    "safety_stock": 15,
    "supplier": {"id": 1, "name": "Supplier", "unit_price": 600, "min_order_qty": 24},
    "sales_history": [{"date": "2026-09-23", "quantity_sold": 20, "stockout": false, "one_time_quantity": 0}]
  }]
}
```

Return an object matching `forecast-response.schema.json`. Include exactly one result for each `(sku, warehouse_id)`, including results with `recommended_quantity: 0`. Do not change stock, incoming quantities, or supplier identity. PHP owns price and supplier terms and ignores remote price overrides. Quantities are integral units. `excluded_one_time_order` is the number of units excluded, not a boolean. `lost_demand` is estimated historical unobserved demand, not automatically added again to the future forecast. `forecast_demand` covers `horizon_days`; `seasonality_index: 1` is neutral.

With `ML_API_URL` empty, PHP's deterministic mock returns this same contract. The UI and stored run explicitly show `source: mock`. The mock uses neutral seasonality, excludes labelled one-time units, estimates stockout loss from available days, and is not a trained forecast model.

Set `ML_API_URL=http://127.0.0.1:9000` to switch to FastAPI without changing the clients. Optional `ML_API_KEY` is sent as a Bearer token. Requests time out after 20 seconds. Malformed, incomplete, duplicate or unsuccessful responses fail the run and preserve previous recommendations. PHP does not silently replace a failed real ML response with a mock.

Forecasts never send orders. A manager explicitly submits reviewed quantities to `POST /api/v1/procurement/approve`; PHP rejects stale stock/sales/supplier snapshots. Incoming stock is recorded only after that confirmation. Physical stock changes on receipt, not approval.

Future Jetson integration should use its own authenticated service identity and the same validated movement/observation API, with an idempotency key and stock version; do not write to MySQL directly. Hardware ingestion and a trained ML model are not implemented in this software MVP.
