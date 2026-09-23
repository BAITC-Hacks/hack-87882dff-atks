"""Allowlisted wrappers. No tool accepts forecasts, quantities, code, or paths."""
import json
from pathlib import Path

import pandas as pd

from intelligence.loader import DATA_DIR
from intelligence.reorder import recommend


class UnknownSKU(Exception):
    pass


class DataUnavailable(Exception):
    pass


def records(frame):
    # Pandas serialization converts NaN/NaT to JSON null and numpy types to primitives.
    result = json.loads(frame.to_json(orient='records', date_format='iso'))
    for row in result:
        if isinstance(row.get('explanation_components'), str):
            row['explanation_components'] = json.loads(row['explanation_components'])
    return result


class Snapshot:
    def __init__(self, directory=DATA_DIR / 'processed'):
        directory = Path(directory)
        try:
            self.frames = {name: pd.read_csv(directory / f'{name}.csv', dtype={'sku': 'string'},
                                             keep_default_na=False, na_values=['']) for name in
                           ('forecasts', 'procurement_recommendations', 'monthly_inventory',
                            'goods_in_transit', 'moq', 'bulk_outliers_audit')}
            self.metadata = json.loads((directory / 'forecast_metrics.json').read_text())
            for name in ('forecasts', 'procurement_recommendations', 'goods_in_transit', 'moq'):
                if self.frames[name].sku.isna().any() or self.frames[name].sku.duplicated().any():
                    raise ValueError('Invalid SKU keys')
            self.skus = set().union(*(set(f.sku.dropna()) for f in self.frames.values()))
        except (OSError, ValueError, KeyError) as exc:
            raise DataUnavailable('Validated pipeline artifacts are unavailable or invalid.') from exc

    def check(self, sku):
        if sku not in self.skus:
            raise UnknownSKU(sku)

    def one(self, name, sku):
        self.check(sku)
        rows = records(self.frames[name].loc[self.frames[name].sku == sku])
        if not rows:
            raise UnknownSKU(sku)
        return rows[0]


class ProcurementTools:
    def __init__(self, snapshot):
        self.snapshot = snapshot

    def get_forecast(self, sku):
        return self.snapshot.one('forecasts', sku)

    def get_recommendation(self, sku):
        return self.snapshot.one('procurement_recommendations', sku)

    def get_inventory(self, sku):
        self.snapshot.check(sku)
        frame = self.snapshot.frames['monthly_inventory']
        rows = frame[frame.sku == sku].sort_values('date')
        latest = records(rows.tail(1))
        known = records(rows[rows.stock.notna()].tail(1))
        return {'sku': sku, 'latest_period': latest[0] if latest else None,
                'latest_known': known[0] if known else None,
                'missing_fields': ['stock'] if not latest or latest[0]['stock'] is None else []}

    def get_in_transit(self, sku):
        self.snapshot.check(sku)
        frame = self.snapshot.frames['goods_in_transit']
        rows = records(frame.loc[frame.sku == sku, ['sku', 'in_transit_quantity']])
        quantity = rows[0]['in_transit_quantity'] if rows else None
        return {'sku': sku, 'in_transit': quantity, 'arrival_date': None,
                'missing_fields': ['arrival_date'] + (['in_transit'] if quantity is None else [])}

    def get_order_multiple(self, sku):
        self.snapshot.check(sku)
        frame = self.snapshot.frames['moq']
        rows = records(frame.loc[frame.sku == sku, ['sku', 'order_multiple']])
        value = rows[0]['order_multiple'] if rows else None
        return {'sku': sku, 'order_multiple': value, 'minimum_order_quantity': None,
                'lead_time': None, 'safety_stock': None,
                'missing_fields': ['minimum_order_quantity', 'lead_time', 'safety_stock'] +
                                  (['order_multiple'] if value is None else [])}

    def get_bulk_adjustments(self, sku):
        self.snapshot.check(sku)
        frame = self.snapshot.frames['bulk_outliers_audit']
        rows = frame.loc[frame.sku == sku]
        return {'sku': sku, 'count': len(rows), 'bulk_quantity_excluded': float(rows.observed_demand.sum()),
                'periods': records(rows[['date', 'observed_demand', 'outlier_score']]),
                'interpretation': 'Monthly bulk candidates, not confirmed individual one-time orders.'}

    def list_replenishment_recommendations(self):
        frame = self.snapshot.frames['procurement_recommendations']
        rows = records(frame[frame.recommended_quantity > 0])
        return {'total': len(rows), 'items': rows}

    def list_review_required(self):
        frame = self.snapshot.frames['procurement_recommendations']
        rows = records(frame[frame.urgency == 'review_required'])
        return {'total': len(rows), 'items': rows}

    def list_transit_affected(self):
        frame = self.snapshot.frames['procurement_recommendations']
        rows = records(frame[frame.transit_reduced_order.eq(True)])
        return {'total': len(rows), 'items': rows}

    def calculate_reorder(self, sku):
        self.snapshot.one('forecasts', sku)
        return records(self.calculate_all(sku))[0]

    def calculate_all(self, sku=None):
        f = self.snapshot.frames
        forecasts = f['forecasts'] if sku is None else f['forecasts'][f['forecasts'].sku == sku]
        if sku is not None:
            self.snapshot.one('forecasts', sku)
        snapshot_month = pd.to_datetime(f['monthly_inventory'].date).max()
        return recommend(forecasts.copy(), f['monthly_inventory'].copy(), f['goods_in_transit'].copy(),
                         f['moq'].copy(), snapshot_month)


TOOL_NAMES = ('get_forecast', 'get_inventory', 'get_in_transit', 'get_order_multiple',
              'get_bulk_adjustments', 'get_recommendation', 'list_replenishment_recommendations',
              'list_review_required', 'calculate_reorder', 'list_transit_affected')


def tool_definitions():
    return [{'type': 'function', 'name': name, 'description': name.replace('_', ' ') +
             '. Returns authoritative saved facts; unknown fields are null. No purchase approval.',
             'strict': True, 'parameters': {'type': 'object', 'properties':
                 {} if name.startswith('list_') else {'sku': {'type': 'string'}},
                 'required': [] if name.startswith('list_') else ['sku'], 'additionalProperties': False}}
            for name in TOOL_NAMES]


def dispatch(tools, name, arguments):
    if name not in TOOL_NAMES or not isinstance(arguments, dict):
        return {'error': 'unsupported_tool'}
    expected = set() if name.startswith('list_') else {'sku'}
    if set(arguments) != expected or ('sku' in arguments and
       (not isinstance(arguments['sku'], str) or not 1 <= len(arguments['sku']) <= 128)):
        return {'error': 'invalid_arguments'}
    try:
        return getattr(tools, name)(**arguments)
    except UnknownSKU:
        return {'error': 'unknown_sku_or_unavailable_output', 'sku': arguments.get('sku')}
