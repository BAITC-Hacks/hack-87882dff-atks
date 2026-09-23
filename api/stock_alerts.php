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
    $alerts = [];
    $warnings = [];
    foreach (R::findAll('purchaseorder', 'status = ? AND recommendation_id IS NOT NULL ORDER BY id DESC', ['draft']) as $order) {
        $alerts[] = $service->details($order);
    }
    usort($alerts, static function ($a, $b) {
        $coverage = static fn($row) => $row['calculation']['current_stock'] / max(0.01, $row['calculation']['average_daily_sales']) - $row['calculation']['lead_time_days'];
        return $coverage($a) <=> $coverage($b);
    });
    jsonResponse(['success' => true, 'count' => count($alerts), 'alerts' => $alerts, 'warnings' => $warnings]);
} catch (Throwable $e) {
    errorResponse('Не удалось обновить рекомендации. Повторите попытку.', 500);
}
