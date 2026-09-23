import unittest

import numpy as np
import pandas as pd

from intelligence.baseline import metrics, nonnegative, predict_baseline, select_models
from intelligence.demand import build_demand
from intelligence.features import build_features, forecast_source, FEATURES
from intelligence.lost_demand import estimate_lost_demand
from intelligence.outliers import detect_bulk_outliers
from intelligence.reorder import recommend


def source(values):
    return pd.DataFrame({'sku': '001_', 'date': pd.date_range('2024-01-01', periods=len(values), freq='MS'),
                         'regular_demand': values, 'observed_demand': values})


def order(stock=3, transit=2, multiple=6, forecast=25, stock_date='2026-09-01'):
    forecasts = pd.DataFrame({'sku': ['001_'], 'forecast_demand': [forecast]})
    inventory = pd.DataFrame({'sku': ['001_'], 'date': [stock_date], 'stock': [stock]})
    goods = pd.DataFrame({'sku': ['001_'], 'in_transit_quantity': [transit]})
    multiples = pd.DataFrame({'sku': ['001_'], 'order_multiple': [multiple]})
    return recommend(forecasts, inventory, goods, multiples, '2026-09-01').iloc[0]


class ForecastTests(unittest.TestCase):
    def test_seasonal_forecast_tracks_same_month_and_sustained_growth(self):
        pattern = [10, 12, 15, 20, 30, 80, 90, 100, 30, 20, 12, 10]
        values = pattern + [v * 1.2 for v in pattern] + [v * 1.44 for v in pattern[:5]] + [np.nan]
        features = build_features(source(values))
        result, fallback = predict_baseline(features.tail(1), 'seasonal_trend')
        self.assertFalse(fallback.iloc[0])
        self.assertAlmostEqual(result[0], 80 * 1.44)
        self.assertGreater(result[0], features.rolling_mean_3.iloc[-1] * 2)
        changed = source(values)
        changed.loc[29, 'regular_demand'] = 99999
        pd.testing.assert_series_equal(build_features(changed).seasonal_trend_prediction, features.seasonal_trend_prediction)

    def test_verified_lost_demand_reaches_forecast_and_order(self):
        regular = source([10.] * 8 + [0., np.nan])
        stockouts = regular[['sku', 'date']].copy()
        stockouts['is_stockout'] = pd.Series(False, index=stockouts.index, dtype='boolean')
        stockouts.loc[8, 'is_stockout'] = True
        corrected = estimate_lost_demand(regular, stockouts)
        before, _ = predict_baseline(build_features(regular).tail(1), 'previous_month')
        after, _ = predict_baseline(build_features(forecast_source(corrected)).tail(1), 'previous_month')
        self.assertEqual(before[0], 0)
        self.assertEqual(after[0], 10)
        self.assertGreater(order(stock=0, transit=0, multiple=1, forecast=after[0]).recommended_quantity,
                           order(stock=0, transit=0, multiple=1, forecast=before[0]).recommended_quantity)
        stockouts.loc[8, 'is_stockout'] = pd.NA
        unknown = forecast_source(estimate_lost_demand(regular, stockouts))
        self.assertEqual(unknown.regular_demand.iloc[8], 0)

    def test_per_sku_selection_uses_validation_not_future(self):
        validation = pd.DataFrame([{'sku': 'seasonal', 'model_name': model, 'actual': 100, 'prediction': prediction}
                                   for model, prediction in [('previous_month', 20), ('seasonal_trend', 100)] for _ in range(6)])
        self.assertEqual(select_models(validation, 'previous_month')['seasonal'], 'seasonal_trend')
        self.assertEqual(select_models(validation.head(2), 'previous_month')['seasonal'], 'previous_month')

    def test_more_transit_cannot_increase_order(self):
        quantities = [order(transit=v).recommended_quantity for v in range(40)]
        self.assertTrue(all(a >= b for a,b in zip(quantities, quantities[1:])))
        self.assertEqual(quantities[-1], 0)

    def test_more_stock_cannot_increase_order(self):
        quantities = [order(stock=v).recommended_quantity for v in range(40)]
        self.assertTrue(all(a >= b for a,b in zip(quantities, quantities[1:])))

    def test_rounding_is_minimal_valid_multiple(self):
        for multiple in (1, 6, 12, 900):
            for demand in (0, 1.2, 20, 21, 1000):
                row = order(multiple=multiple, forecast=demand)
                self.assertEqual(row.recommended_quantity % multiple, 0)
                self.assertGreaterEqual(row.recommended_quantity, row.net_requirement)
                self.assertLess(row.recommended_quantity - row.net_requirement, multiple)

    def test_bulk_does_not_inflate_forecast_input(self):
        data = source([10] * 8 + [1000, 10, 10]).rename(columns={'regular_demand': 'demand'})
        data['source_row'] = 3
        cleaned = detect_bulk_outliers(build_demand(data.drop(columns='observed_demand')))
        f = build_features(cleaned)
        self.assertTrue(pd.isna(f.lag_1.iloc[9]))
        self.assertEqual(f.rolling_mean_3.iloc[9], 10)
        self.assertEqual(f.history_mean.iloc[9], 10)
        predicted, _ = predict_baseline(f.iloc[[9]], 'previous_month')
        self.assertEqual(predicted[0], 10)

    def test_features_are_unchanged_by_current_or_future_target(self):
        a = source(list(range(1, 19)))
        b = a.copy()
        b.loc[12:, ['regular_demand', 'observed_demand']] = 999999
        fa, fb = build_features(a), build_features(b)
        pd.testing.assert_frame_equal(fa.loc[:12, FEATURES], fb.loc[:12, FEATURES])
        self.assertEqual(fa.lag_1.iloc[12], 12)
        self.assertEqual(fa.lag_12.iloc[12], 1)
        self.assertEqual(fa.rolling_mean_3.iloc[12], 11)

    def test_calendar_seasonality_has_no_demand_dependency(self):
        a, b = build_features(source([1] * 15)), build_features(source([900] * 15))
        for feature in ('month_sin', 'month_cos', 'month_of_year', 'quarter', 'time_index'):
            pd.testing.assert_series_equal(a[feature], b[feature])
        self.assertAlmostEqual(a.month_sin.iloc[0], a.month_sin.iloc[12])

    def test_lags_align_to_calendar_not_previous_record(self):
        data = source([10, 15, 20]).drop(index=1)
        f = build_features(data)
        self.assertEqual(len(f), 3)
        self.assertTrue(pd.isna(f.lag_1.iloc[2]))
        self.assertEqual(f.lag_2.iloc[2], 10)

    def test_predictions_are_nonnegative(self):
        np.testing.assert_array_equal(nonnegative([-100, -0.1, 0, 2]), [0, 0, 0, 2])

    def test_missing_and_stale_inputs_block_order(self):
        for row in (order(stock=np.nan), order(transit=np.nan), order(multiple=np.nan),
                    order(multiple=0), order(stock_date='2026-08-01')):
            self.assertTrue(pd.isna(row.recommended_quantity))
            self.assertEqual(row.urgency, 'review_required')

    def test_zero_is_known_input(self):
        row = order(stock=0, transit=0, multiple=1, forecast=10)
        self.assertEqual(row.recommended_quantity, 10)
        self.assertEqual(row.urgency, 'stockout')

    def test_wape_handles_zero_demand(self):
        score = metrics([0, 10], [2, 8])
        self.assertEqual(score['wape_pct'], 40)
        self.assertIsNone(metrics([0], [2])['wape_pct'])


if __name__ == '__main__':
    unittest.main()
