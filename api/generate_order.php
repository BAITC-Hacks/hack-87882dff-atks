<?php

declare(strict_types=1);

use SmartStock\OrderService;
use SmartStock\ReorderCalculator;

require_once __DIR__ . '/../config.php';
handlePreflight();
requireMethod('POST');
try {
    connectDb();
    $body = readJsonBody();
    $productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $warehouseId = filter_var($body['warehouse_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$productId || !$warehouseId) {
        errorResponse('Укажите товар и склад', 400);
    }
    $calculation = (new ReorderCalculator())->calculateReorderPoint($productId, $warehouseId);
    if (!$calculation['needs_reorder']) {
        errorResponse('Запас в норме, пополнение не требуется', 409);
    }
    $order = (new OrderService())->draft($productId, $warehouseId, $calculation);
    jsonResponse(['success' => true, 'order' => beanToArray($order), 'calculation' => $calculation]);
} catch (RuntimeException $e) {
    errorResponse($e->getMessage(), 422);
} catch (Throwable $e) {
    errorResponse('Не удалось создать заказ', 500);
}
