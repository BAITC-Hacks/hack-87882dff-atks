<?php
declare(strict_types=1);
// Synthetic transport fixture, not the teammate's Swagger contract.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/health') { echo '{"status":"ok","agent":{"status":"unavailable"}}'; exit; }
if ($path === '/openapi.json') {
    echo '{"openapi":"3.1.0","paths":{"/health":{"get":{}},"/agent/run":{"post":{}}}}'; exit;
}
if ($path === '/forecast/030200203_') { echo '{"sku":"030200203_","forecast_demand":4928.0}'; exit; }
if ($path === '/recommendation/030200203_') { echo '{"sku":"030200203_","recommended_quantity":900.0,"order_multiple":900.0}'; exit; }
if ($path === '/recommendations') {
    echo json_encode(['items' => [
        ['sku' => '030200203_', 'recommended_quantity' => 900.0, 'order_multiple' => 900.0, 'status' => 'ready'],
        ['sku' => 'UNKNOWN-INPUT', 'recommended_quantity' => null, 'status' => 'review_required'],
    ], 'filter_received' => $_GET['status'] ?? null]); exit;
}
if ($path === '/agent/run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'));
    if (!empty($payload->fixture_unavailable)) { http_response_code(503); echo '{"detail":"private provider error"}'; exit; }
    echo json_encode(['answer' => 'Fixture only', 'tools_used' => ['get_recommendation'], 'data' => ['request' => $payload, 'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null], 'requires_human_review' => true]); exit;
}
if ($path === '/redirect/health') { http_response_code(302); header('Location: /health'); echo '{}'; exit; }
http_response_code(404); echo '{"detail":"Not found"}';
