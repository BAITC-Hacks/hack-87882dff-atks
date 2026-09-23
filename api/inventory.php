<?php

declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\ReorderCalculator;
use SmartStock\OrderService;

require_once __DIR__ . '/../config.php';
handlePreflight();
requireMethod('GET');
try {
    connectDb();
    $items = [];
    foreach (R::findAll('stock', 'ORDER BY id ASC') as $stock) {
        $product = R::load('product', (int) $stock->product_id);
        $warehouse = R::load('warehouse', (int) $stock->warehouse_id);
        try {
            $calculation = (new ReorderCalculator())->calculateReorderPoint((int) $product->id, (int) $warehouse->id);
        } catch (RuntimeException $e) {
            $calculation = null;
        }
        $incoming = (int) R::getCell('SELECT COALESCE(SUM(quantity-received_qty), 0) FROM intransit WHERE product_id = ? AND warehouse_id = ? AND status = ?', [$product->id, $warehouse->id, 'expected']);
        $items[] = ['product' => beanToArray($product), 'warehouse' => beanToArray($warehouse), 'stock' => beanToArray($stock), 'calculation' => $calculation, 'incoming_qty' => $incoming, 'history' => (new OrderService())->history((int) $product->id, (int) $warehouse->id)];
    }
    jsonResponse(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    errorResponse('Не удалось загрузить остатки', 500);
}
