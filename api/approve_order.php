<?php

declare(strict_types=1);

use RedBeanPHP\R;

require_once __DIR__ . '/../config.php';

handlePreflight();
requireMethod('POST');

try {
    connectDb();
    $body = readJsonBody();
    $orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($orderId <= 0) {
        errorResponse('order_id обязателен', 400);
    }

    $order = R::load('purchaseorder', $orderId);
    if (!$order->id) {
        errorResponse('Заказ не найден', 404);
    }

    if (!in_array($order->status, ['draft', 'approved'], true)) {
        errorResponse('Этот заказ нельзя подтвердить', 409);
    }
    if ($order->status === 'draft') {
        $lock = 'smartstock:' . $order->product_id . ':' . $order->warehouse_id;
        if ((int) R::getCell('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            errorResponse('Заказ обновляется. Повторите попытку.', 409);
        }
        try {
            $order = R::load('purchaseorder', $orderId);
            if ($order->status === 'draft') {
                $confirmed = R::findOne('purchaseorder', 'product_id = ? AND warehouse_id = ? AND status = ?', [$order->product_id, $order->warehouse_id, 'approved']);
                if ($confirmed) {
                    errorResponse('Пополнение этого товара уже подтверждено. Обновите список.', 409);
                }
                $order->status = 'approved';
                $order->approved_at = date('Y-m-d H:i:s');
                R::store($order);
            }
        } finally {
            R::getCell('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    jsonResponse([
        'success' => true,
        'order' => beanToArray($order),
    ]);
} catch (Throwable $e) {
    errorResponse('Не удалось подтвердить заказ. Повторите попытку.', 500);
}
