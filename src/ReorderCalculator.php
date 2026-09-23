<?php

declare(strict_types=1);

namespace SmartStock;

use RedBeanPHP\R;
use RuntimeException;

class ReorderCalculator
{
    public function calculateReorderPoint(int $productId, int $warehouseId): array
    {
        $product = R::load('product', $productId);
        $stock = R::findOne('stock', 'product_id = ? AND warehouse_id = ?', [$productId, $warehouseId]);

        if (!$product->id) {
            throw new RuntimeException('Товар не найден');
        }

        if (!$stock) {
            throw new RuntimeException('Остаток товара на выбранном складе не найден');
        }

        $history = R::findAll(
            'saleshistory',
            'product_id = ? AND warehouse_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC',
            [$productId, $warehouseId, date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]
        );

        if (count($history) === 0) {
            throw new RuntimeException('Нет истории продаж для расчёта');
        }

        $sales = array_map(static fn($row): int => (int) $row->quantity_sold, array_values($history));
        $days = count($sales);
        $averageDailySales = array_sum($sales) / $days;
        $stdDev = $this->standardDeviation($sales, $averageDailySales);
        $leadTimeDays = max(1, (int) $product->lead_time_days);
        $currentStock = (int) $stock->quantity_on_hand;

        // Safety stock по уровню сервиса 95%: z-score 1.65.
        $calculatedSafetyStock = 1.65 * $stdDev * sqrt($leadTimeDays);
        $configuredSafetyStock = (float) $stock->safety_stock;
        $safetyStock = max($configuredSafetyStock, $calculatedSafetyStock);
        $reorderPoint = ($averageDailySales * $leadTimeDays) + $safetyStock;
        $reviewPeriodDemand = $averageDailySales * 7;
        $suggestedQtyRaw = $reorderPoint - $currentStock + $reviewPeriodDemand;
        $suggestedQty = max(0, (int) ceil($suggestedQtyRaw));

        $lastWeekAverage = array_sum(array_slice($sales, -7)) / min(7, $days);
        $previousAverage = $days > 7 ? array_sum(array_slice($sales, 0, $days - 7)) / ($days - 7) : $averageDailySales;

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'days_analyzed' => $days,
            'current_stock' => $currentStock,
            'lead_time_days' => $leadTimeDays,
            'average_daily_sales' => round($averageDailySales, 2),
            'std_dev' => round($stdDev, 2),
            'configured_safety_stock' => round($configuredSafetyStock, 2),
            'calculated_safety_stock' => round($calculatedSafetyStock, 2),
            'safety_stock' => round($safetyStock, 2),
            'reorder_point' => (int) ceil($reorderPoint),
            'review_period_days' => 7,
            'review_period_demand' => round($reviewPeriodDemand, 2),
            'suggested_qty' => $suggestedQty,
            'needs_reorder' => $currentStock < (int) ceil($reorderPoint),
            'last_week_average' => round($lastWeekAverage, 2),
            'previous_period_average' => round($previousAverage, 2),
            'demand_change_percent' => $previousAverage > 0
                ? round((($lastWeekAverage - $previousAverage) / $previousAverage) * 100, 2)
                : 0.0,
        ];
    }

    private function standardDeviation(array $values, float $mean): float
    {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }

        $variance = array_sum(array_map(
            static fn($value): float => ($value - $mean) ** 2,
            $values
        )) / $count;

        return sqrt($variance);
    }
}
