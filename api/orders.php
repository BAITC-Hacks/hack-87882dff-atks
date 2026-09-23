<?php

declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\OrderService;

require_once __DIR__ . '/../config.php';
handlePreflight();
requireMethod('GET');
try {
    connectDb();
    $service = new OrderService();
    $rows = R::findAll('purchaseorder', 'status = ? OR (status = ? AND NOT EXISTS (SELECT 1 FROM purchaseorder confirmed WHERE confirmed.product_id = purchaseorder.product_id AND confirmed.warehouse_id = purchaseorder.warehouse_id AND confirmed.status = ?)) ORDER BY id DESC', ['approved', 'draft', 'approved']);
    $orders = array_values(array_map(fn($order) => $service->details($order), $rows));
    jsonResponse(['success' => true, 'orders' => $orders]);
} catch (Throwable $e) {
    errorResponse('Не удалось загрузить заказы', 500);
}
