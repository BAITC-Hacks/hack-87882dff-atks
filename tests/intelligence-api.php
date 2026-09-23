<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use SmartStock\ApiException;
use SmartStock\IntelligenceApiService;

class FakeIntelligenceApi extends IntelligenceApiService
{
    public array $calls = [];
    public array $response = ['status' => 200, 'errno' => 0, 'body' => '{}'];
    protected function send(string $method, string $url, ?string $body): array
    {
        $this->calls[] = [$method, $url, $body];
        return $this->response;
    }
}

$checks = 0;
function checkIntelligence(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($name); }
    echo 'PASS: ' . $name . PHP_EOL; $checks++;
}
function rejectsIntelligence(callable $call, int $status, string $name): void {
    try { $call(); throw new RuntimeException('Unexpected success: ' . $name); }
    catch (ApiException $e) { checkIntelligence($e->status === $status && !str_contains($e->getMessage(), 'provider-secret'), $name); }
}

$fake = new FakeIntelligenceApi('http://127.0.0.1:9999/api/');
$fake->health(); $fake->schema(); $fake->getForecast('030200203_'); $fake->getRecommendation('030200203_');
checkIntelligence(array_column($fake->calls, 1) === ['http://127.0.0.1:9999/api/health', 'http://127.0.0.1:9999/api/openapi.json', 'http://127.0.0.1:9999/api/forecast/030200203_', 'http://127.0.0.1:9999/api/recommendation/030200203_'], 'Documented GET paths and leading-zero SKU preserved');
$fake->getForecast('item/with?query');
checkIntelligence(str_ends_with($fake->calls[4][1], '/forecast/item%2Fwith%3Fquery'), 'SKU cannot introduce a route or query');
$fake->getRecommendations('review_required');
checkIntelligence(str_ends_with($fake->calls[5][1], '/recommendations?status=review_required'), 'Manual review filter is forwarded');
$fake->response['body'] = '{"items":[{"sku":"030200203_","recommended_quantity":900.0,"order_multiple":900.0},{"sku":"MISSING","status":"review_required","recommended_quantity":null}],"metadata":{}}';
$result = $fake->getRecommendations();
checkIntelligence($result->items[0]->recommended_quantity === 900.0 && $result->items[1]->recommended_quantity === null && $result->items[1]->status === 'review_required' && $result->metadata instanceof stdClass, 'Numbers, null inputs, review rows and empty objects are unchanged');
$fake->response['body'] = '[{"sku":"TEST","recommended_quantity":0.25}]';
checkIntelligence($fake->getRecommendations()[0]->recommended_quantity === 0.25, 'Top-level lists and fractional units are not coerced');
$fake->response['body'] = '{"answer":"fixture","tools_used":["get_forecast"],"data":{},"requires_human_review":true}';
$payload = json_decode('{"fixture_prompt":"test","context":{},"history":[]}');
$result = $fake->runAgent($payload);
$call = end($fake->calls);
checkIntelligence($call[0] === 'POST' && str_ends_with($call[1], '/agent/run') && json_decode($call[2]) == $payload && $result->requires_human_review, 'Agent body follows supplied schema and retains review flag');

foreach (['file:///tmp/model', 'ftp://example.invalid', 'http://name:password@example.invalid', 'http://example.invalid?key=x', 'http://example.invalid/#fragment'] as $url) {
    rejectsIntelligence(fn() => new FakeIntelligenceApi($url), 503, 'Invalid server URL rejected');
}
rejectsIntelligence(fn() => (new FakeIntelligenceApi(''))->health(), 503, 'Missing URL never falls back to PHP calculations');
rejectsIntelligence(fn() => $fake->getForecast('..'), 422, 'Dot path rejected');
rejectsIntelligence(fn() => $fake->getForecast(''), 422, 'Empty SKU rejected');
rejectsIntelligence(fn() => $fake->getRecommendations('review_required&other=value'), 422, 'Invalid status rejected');
rejectsIntelligence(fn() => $fake->runAgent((object)['fixture_prompt' => str_repeat('x', 65537)]), 413, 'Oversized agent body rejected before HTTP');
foreach ([[0, 28, 504], [0, 7, 503], [401, 0, 502], [403, 0, 502], [404, 0, 404], [422, 0, 422], [429, 0, 429], [500, 0, 503], [302, 0, 503]] as [$upstream, $errno, $expected]) {
    $fake->response = ['status' => $upstream, 'errno' => $errno, 'body' => '{"detail":"provider-secret"}'];
    rejectsIntelligence(fn() => $fake->health(), $expected, 'Sanitized upstream error ' . $upstream . '/' . $errno);
}
foreach (['not-json', 'null', '42', '"scalar"'] as $body) {
    $fake->response = ['status' => 200, 'errno' => 0, 'body' => $body];
    rejectsIntelligence(fn() => $fake->health(), 502, 'Invalid JSON response rejected');
}
$fake->response = ['status' => 200, 'errno' => 0, 'body' => '{}'];
rejectsIntelligence(fn() => $fake->runAgent($payload), 502, 'Incomplete agent response rejected');
$fake->response['too_large'] = true;
rejectsIntelligence(fn() => $fake->health(), 502, 'Oversized response rejected');

$server = null; $pipes = [];
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException($error); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/intelligence-router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($server)) { throw new RuntimeException('Could not start local fixture server'); }
    fclose($pipes[0]); unset($pipes[0]);
    $client = new IntelligenceApiService('http://' . $address);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        try { $ready = $client->health()->status === 'ok'; break; }
        catch (ApiException $e) { usleep(100000); }
    }
    checkIntelligence($ready, 'Real cURL reaches isolated local fixture');
    checkIntelligence(isset($client->schema()->paths->{'/health'}), 'OpenAPI transport works');
    checkIntelligence($client->getForecast('030200203_')->forecast_demand === 4928.0, 'Forecast comes from remote response without recalculation');
    checkIntelligence($client->getRecommendation('030200203_')->recommended_quantity === 900.0, 'Recommended quantity is unchanged');
    $result = $client->getRecommendations('review_required');
    checkIntelligence($result->filter_received === 'review_required' && $result->items[1]->recommended_quantity === null, 'Real cURL retains missing-input recommendation');
    $result = $client->runAgent($payload);
    checkIntelligence($result->data->request == $payload && $result->data->authorization === null, 'Agent request preserves JSON objects and forwards no credentials');
    rejectsIntelligence(fn() => $client->runAgent((object)['fixture_unavailable' => true]), 503, 'Agent outage is explicit');
    checkIntelligence(count($client->getRecommendations()->items) === 2, 'Recommendations remain available after agent failure');
    rejectsIntelligence(fn() => (new IntelligenceApiService('http://' . $address . '/redirect'))->health(), 503, 'Real cURL does not follow redirects');
    echo "\n{$checks} intelligence checks passed without external API calls.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); }
    foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    if (is_resource($server)) { proc_close($server); }
}
