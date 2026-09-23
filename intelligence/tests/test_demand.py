import unittest

import numpy as np
import pandas as pd

from intelligence.demand import build_demand
from intelligence.outliers import detect_bulk_outliers
from intelligence.stockout import detect_stockouts
from intelligence.lost_demand import estimate_lost_demand
from intelligence.reconciliation import reconcile


def monthly(values, sku='00123_'):
    return pd.DataFrame({'sku': sku, 'date': pd.date_range('2024-01-01', periods=len(values), freq='MS'),
                         'demand': values, 'source_row': 3})


def inventory(values):
    return monthly(values).rename(columns={'demand': 'stock'})


class DemandTests(unittest.TestCase):
    def test_one_time_bulk_does_not_inflate_regular_demand(self):
        demand = build_demand(monthly([10] * 8 + [1000] + [10] * 3))
        result = detect_bulk_outliers(demand)
        self.assertEqual(result.is_bulk_outlier.sum(), 1)
        self.assertTrue(result.is_bulk_outlier.iloc[8])
        self.assertTrue(pd.isna(result.regular_demand.iloc[8]))
        self.assertEqual(result.observed_demand.iloc[8], 1000)
        self.assertEqual(result.regular_demand.mean(), 10)
        self.assertEqual(result.exclusion_reason.iloc[8], 'bulk_period_candidate')

    def test_confirmed_stockout_supported_by_history(self):
        demand = detect_bulk_outliers(build_demand(monthly([10] * 8 + [0])))
        stock = detect_stockouts(inventory([5] * 8 + [0]))
        result = estimate_lost_demand(demand, stock)
        row = result.iloc[-1]
        self.assertTrue(row.is_stockout)
        self.assertEqual(row.observed_demand, 0)
        self.assertGreater(row.corrected_demand, row.observed_demand)
        self.assertEqual(row.estimated_lost_demand, 10)
        self.assertEqual(row.corrected_demand, row.regular_demand + row.estimated_lost_demand)

    def test_missing_inventory_is_unknown_not_stockout(self):
        stock = detect_stockouts(inventory([5] * 8 + [None]))
        self.assertTrue(pd.isna(stock.is_stockout.iloc[-1]))
        demand = detect_bulk_outliers(build_demand(monthly([10] * 8 + [0])))
        result = estimate_lost_demand(demand, stock)
        self.assertTrue(pd.isna(result.estimated_lost_demand.iloc[-1]))
        self.assertEqual(result.corrected_demand.iloc[-1], 0)

    def test_returns_and_missing_demand_remain_auditable(self):
        demand = build_demand(monthly([10] * 8 + [-3, None]))
        self.assertTrue(demand.is_return_or_correction.iloc[8])
        self.assertTrue(pd.isna(demand.is_return_or_correction.iloc[9]))
        result = detect_bulk_outliers(demand)
        self.assertEqual(result.observed_demand.iloc[8], -3)
        self.assertTrue(pd.isna(result.regular_demand.iloc[8]))
        self.assertEqual(result.exclusion_reason.iloc[8], 'negative_net_correction')
        self.assertEqual(result.source_row.iloc[8], 3)
        self.assertFalse(result.is_bulk_outlier.iloc[8])

    def test_insufficient_history_never_invents_lost_demand(self):
        demand = detect_bulk_outliers(build_demand(monthly([10, 10, 0])))
        result = estimate_lost_demand(demand, detect_stockouts(inventory([5, 5, 0])))
        self.assertTrue(pd.isna(result.estimated_lost_demand.iloc[-1]))
        self.assertEqual(result.corrected_demand.iloc[-1], 0)
        self.assertEqual(result.correction_status.iloc[-1], 'insufficient_history')

    def test_missing_observation_at_stockout_is_not_zero(self):
        demand = detect_bulk_outliers(build_demand(monthly([10] * 8 + [None])))
        result = estimate_lost_demand(demand, detect_stockouts(inventory([5] * 8 + [0])))
        self.assertTrue(pd.isna(result.corrected_demand.iloc[-1]))
        self.assertTrue(pd.isna(result.estimated_lost_demand.iloc[-1]))

    def test_no_future_leakage(self):
        first = build_demand(monthly([10] * 8 + [0, 10, 10]))
        second = build_demand(monthly([10] * 8 + [0, 9000, 9000]))
        stock = detect_stockouts(inventory([5] * 8 + [0, 5, 5]))
        a = estimate_lost_demand(detect_bulk_outliers(first), stock)
        b = estimate_lost_demand(detect_bulk_outliers(second), stock)
        self.assertEqual(a.corrected_demand.iloc[8], b.corrected_demand.iloc[8])
        pd.testing.assert_series_equal(a.outlier_score.iloc[:9], b.outlier_score.iloc[:9])

    def test_reconciliation_missing_values_and_sign_methods(self):
        tx = pd.DataFrame({'sku': ['00123_'] * 4,
                           'date': pd.to_datetime(['2024-01-01', '2024-01-02', '2024-02-01', '2024-02-02']),
                           'quantity': [10, -2, 5, np.nan], 'source_row': [2, 3, 4, 5],
                           'document': ['Invoice 123 от 01.01.2024'] * 4})
        report, pairs, _ = reconcile(tx, monthly([8, 5]))
        self.assertEqual(pairs.A.iloc[0], 10)
        self.assertEqual(pairs.B.iloc[0], 2)
        self.assertEqual(pairs.C.iloc[0], 8)
        self.assertEqual(pairs.D.iloc[0], 12)
        self.assertTrue(pairs.loc[1, ['A', 'B', 'C', 'D']].isna().all())
        self.assertIsNone(report['methods']['C']['all_matched_pairs']['mae'])
        self.assertEqual(report['methods']['C']['excluding_missing_monthly_sales']['mae'], 0)
        self.assertEqual(report['signs']['missing']['count'], 1)
        self.assertIsNone(report['signs']['missing']['total_quantity'])

    def test_constant_zero_history_handles_mad_zero(self):
        result = detect_bulk_outliers(build_demand(monthly([0] * 8 + [100])))
        self.assertTrue(result.is_bulk_outlier.iloc[-1])
        self.assertTrue(np.isfinite(result.outlier_score.iloc[-1]))

    def test_duplicate_months_rejected(self):
        source = monthly([10])
        with self.assertRaises(ValueError):
            build_demand(pd.concat([source, source]))
        with self.assertRaises(ValueError):
            detect_stockouts(pd.concat([inventory([1]), inventory([1])]))

    def test_available_stock_never_gets_uplift(self):
        demand = detect_bulk_outliers(build_demand(monthly([10] * 8 + [1])))
        result = estimate_lost_demand(demand, detect_stockouts(inventory([5] * 9)))
        self.assertEqual(result.estimated_lost_demand.iloc[-1], 0)
        self.assertEqual(result.corrected_demand.iloc[-1], 1)


if __name__ == '__main__':
    unittest.main()
