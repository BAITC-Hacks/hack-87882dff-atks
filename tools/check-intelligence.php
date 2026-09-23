<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';

try {
    $client = new SmartStock\IntelligenceApiService();
    $health = $client->health();
    $schema = $client->schema();
    $paths = $schema instanceof stdClass && isset($schema->paths) && $schema->paths instanceof stdClass
        ? array_keys(get_object_vars($schema->paths)) : [];
    $ok = $health instanceof stdClass && ($health->status ?? null) === 'ok' && count($paths) > 0;
    echo json_encode(['reachable' => true, 'healthy' => $ok, 'status' => $health->status ?? null, 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (SmartStock\ApiException $e) {
    echo json_encode(['reachable' => false, 'error' => $e->getMessage(), 'status' => $e->status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
