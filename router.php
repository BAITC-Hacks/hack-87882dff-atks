<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

handlePreflight();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/v1/')) { require __DIR__ . '/api/v1.php'; exit; }
if ($path === '/manager/qoyma.png') {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/web/qoyma.png');
    exit;
}
$webFiles = ['/manager' => 'index.html', '/manager/' => 'index.html', '/manager/app.js' => 'app.js', '/manager/intelligence.js' => 'intelligence.js', '/manager/styles.css' => 'styles.css', '/manager/icons.js' => 'node_modules/lucide/dist/umd/lucide.js'];
if (isset($webFiles[$path])) {
    $file = __DIR__ . '/web/' . $webFiles[$path];
    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        header('Content-Type: ' . (['html' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript'][$ext]) . '; charset=utf-8');
        readfile($file); exit;
    }
}
$endpoints = ['dashboard_summary', 'stock_alerts', 'generate_order', 'approve_order', 'orders', 'inventory', 'analyze_order'];
foreach ($endpoints as $endpoint) {
    if ($path === '/api/' . $endpoint . '.php') {
        try {
            connectDb();
            \SmartStock\Auth::user(in_array($endpoint, ['approve_order', 'generate_order', 'analyze_order'], true) ? 'manager' : null);
        } catch (\SmartStock\ApiException $e) { errorResponse($e->getMessage(), $e->status); }
        if (in_array($endpoint, ['approve_order', 'generate_order'], true)) {
            errorResponse('Используйте раздел закупок SupplyMind для расчёта и подтверждения рекомендаций', 410);
        }
        require __DIR__ . '/api/' . $endpoint . '.php';
        exit;
    }
}

if ($path === '/' || $path === '') {
    jsonResponse([
        'success' => true,
        'service' => 'SupplyMind AI backend',
        'manager_panel' => '/manager',
        'endpoints' => [
            'POST /api/v1/login',
            'GET /api/v1/workspace',
            'POST /api/v1/procurement/run',
            'POST /api/v1/procurement/approve',
            'POST /api/v1/movements',
        ],
    ]);
}

errorResponse('Endpoint не найден', 404, ['path' => $path]);
