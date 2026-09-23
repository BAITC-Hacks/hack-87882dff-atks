<?php

declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\AiExplainer;
use SmartStock\OrderService;
use SmartStock\ReorderCalculator;

require_once __DIR__ . '/../config.php';
handlePreflight();
requireMethod('POST');
try {
    connectDb();
    $body = readJsonBody();
    $id = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        errorResponse('Укажите заказ', 400);
    }
    $order = R::load('purchaseorder', $id);
    if (!$order->id) {
        errorResponse('Заказ не найден', 404);
    }
    $calculation = (new ReorderCalculator())->calculateReorderPoint((int) $order->product_id, (int) $order->warehouse_id);
    if ($order->ai_source === 'openai') {
        jsonResponse(['success' => true, 'analysis' => ['text' => (string) $order->ai_explanation, 'source' => 'openai', 'reason' => null]]);
    }
    $product = R::load('product', (int) $order->product_id);
    $history = (new OrderService())->history((int) $order->product_id, (int) $order->warehouse_id);
    $fingerprint = $order->calculation_hash;
    $analysis = (new AiExplainer())->analyze($product, [...$calculation, 'suggested_qty' => (int) $order->suggested_qty], $history);
    $order = R::load('purchaseorder', $id);
    if ($order->calculation_hash !== $fingerprint) {
        errorResponse('Расчёт обновился. Запустите анализ повторно.', 409);
    }
    $order->ai_explanation = $analysis['text'];
    $order->ai_source = $analysis['source'];
    $order->ai_reason = $analysis['reason'];
    R::store($order);
    jsonResponse(['success' => true, 'analysis' => $analysis]);
} catch (Throwable $e) {
    errorResponse('Анализ временно недоступен. Расчёт заказа сохранён.', 500);
}
