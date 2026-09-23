<?php

declare(strict_types=1);

use RedBeanPHP\R;

require_once __DIR__ . '/../config.php';

handlePreflight();
requireMethod('GET');

try {
    connectDb();

    $totalProducts = (int) R::count('product');
    $lowStockCount = (int) R::count('stock', 'quantity_on_hand < reorder_point');
    $pendingOrdersCount = (int) R::count('purchaseorder', 'status = ? AND NOT EXISTS (SELECT 1 FROM purchaseorder confirmed WHERE confirmed.product_id = purchaseorder.product_id AND confirmed.warehouse_id = purchaseorder.warehouse_id AND confirmed.status = ?)', ['draft', 'approved']);
    $stockRows = R::getAll(
        'SELECT s.quantity_on_hand, p.unit_cost
         FROM stock s
         INNER JOIN product p ON p.id = s.product_id'
    );

    $totalStockValue = 0.0;
    foreach ($stockRows as $row) {
        $totalStockValue += (float) $row['quantity_on_hand'] * (float) $row['unit_cost'];
    }

    jsonResponse([
        'success' => true,
        'summary' => [
            'total_products' => $totalProducts,
            'low_stock_count' => $lowStockCount,
            'total_stock_value' => round($totalStockValue, 2),
            'pending_orders_count' => $pendingOrdersCount,
        ],
    ]);
} catch (Throwable $e) {
    errorResponse('Не удалось получить dashboard summary', 500, ['message' => $e->getMessage()]);
}
