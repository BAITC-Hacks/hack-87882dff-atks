<?php
declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\ApiException;
use SmartStock\ForecastService;

require_once __DIR__ . '/../config.php';
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__);
if (!is_dir($root . '/.tmp')) { mkdir($root . '/.tmp', 0777, true); }
$database = 'supplymind_test_' . bin2hex(random_bytes(6));
$admin = new PDO('mysql:host=' . dbConfig('DB_HOST', 'localhost') . ';charset=utf8mb4', dbConfig('DB_USER', 'root'), dbConfig('DB_PASSWORD', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = null;
$checks = 0;
function check(bool $value, string $message): void {
    global $checks;
    if (!$value) { throw new RuntimeException($message); }
    echo 'PASS: ' . $message . PHP_EOL; $checks++;
}
function http(string $path, ?array $body = null, ?string $token = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) { $headers[] = 'Authorization: Bearer ' . $token; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]); }
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, json_decode((string)$raw, true), $raw];
}
function api(string $path, ?array $body, ?string $token, int $expected = 200): array {
    [$status, $result, $raw] = http('/api/v1/' . $path, $body, $token);
    check($status === $expected, $path . ' HTTP ' . $expected . ($status === $expected ? '' : ' got ' . $status . ': ' . $raw));
    return $result;
}
function insert(string $table, array $data): int {
    $columns = implode(',', array_keys($data)); $marks = implode(',', array_fill(0, count($data), '?'));
    R::exec("INSERT INTO {$table} ({$columns}) VALUES ({$marks})", array_values($data));
    return (int)R::getCell('SELECT LAST_INSERT_ID()');
}
function keyForRequest(): string { return 'test-' . bin2hex(random_bytes(10)); }

try {
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv('DB_NAME=' . $database); putenv('ML_API_URL='); putenv('ML_API_KEY='); putenv('OPENAI_API_KEY='); putenv('INTELLIGENCE_API_URL=');
    require $root . '/setup.php';
    R::freeze(true);
    $w1 = insert('warehouse', ['name' => 'Test source', 'location' => 'Test']);
    $w2 = insert('warehouse', ['name' => 'Test destination', 'location' => 'Test']);
    $s1 = insert('supplier', ['name' => 'Test supplier A', 'contact_info' => 'test@example.invalid', 'avg_lead_time_days' => 4]);
    $s2 = insert('supplier', ['name' => 'Test supplier B', 'contact_info' => 'test@example.invalid', 'avg_lead_time_days' => 4]);
    $products = [];
    for ($i = 1; $i <= 3; $i++) {
        $id = insert('product', ['sku' => 'TEST-' . $i, 'name' => 'Fixture ' . $i, 'category' => 'Test', 'barcode' => '00123456789' . $i, 'unit_cost' => 100, 'lead_time_days' => 4]);
        $products[] = $id;
        insert('supplierproduct', ['product_id' => $id, 'supplier_id' => $i === 3 ? $s2 : $s1, 'unit_price' => 90, 'min_order_qty' => 5]);
        insert('stock', ['product_id' => $id, 'warehouse_id' => $w1, 'quantity_on_hand' => 20, 'safety_stock' => 5, 'reorder_point' => 40]);
        for ($day = 29; $day >= 0; $day--) {
            insert('saleshistory', ['product_id' => $id, 'warehouse_id' => $w1, 'date' => date('Y-m-d', strtotime("-{$day} days")), 'quantity_sold' => $day === 15 ? 110 : ($day === 10 ? 0 : 10), 'one_time_quantity' => $day === 15 ? 100 : 0, 'stockout' => $day === 10 ? 1 : 0]);
        }
    }
    for ($repeat = 0; $repeat < 2; $repeat++) {
        $migration = proc_open([PHP_BINARY, 'setup.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $migrationPipes, $root, null, ['bypass_shell' => true]);
        fclose($migrationPipes[0]); $migrationOutput = stream_get_contents($migrationPipes[1]) . stream_get_contents($migrationPipes[2]); fclose($migrationPipes[1]); fclose($migrationPipes[2]);
        check(proc_close($migration) === 0 && R::count('product') === 3, 'Schema upgrade is repeatable with existing products: ' . trim($migrationOutput));
    }
    require __DIR__ . '/intelligence-results-cases.php';
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException($error); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address;
    $server = proc_open([PHP_BINARY, '-S', $address, 'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/.tmp/test-api.log', 'a'], 2 => ['file', $root . '/.tmp/test-api.log', 'a']], $pipes, $root, null, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($server)) { throw new RuntimeException('Could not start isolated API server'); }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 40; $attempt++) { if (http('/')[0] === 200) break; usleep(100000); }
    check(http('/manager')[0] === 200 && http('/manager/icons.js')[0] === 200, 'Manager panel and local assets served');
    check(http('/.env')[0] === 404 && http('/setup.php')[0] === 404, 'Secrets and setup are not HTTP-accessible');
    api('workspace', null, null, 401);
    api('login', ['email' => 'manager@supplymind.local', 'password' => 'incorrect'], null, 401);
    $manager = api('login', ['email' => 'manager@supplymind.local', 'password' => 'SupplyMind2026!'], null);
    $staff = api('login', ['email' => 'warehouse@supplymind.local', 'password' => 'SupplyMind2026!'], null);
    $mt = $manager['token']; $st = $staff['token'];
    api('intelligence/health', null, null, 401);
    api('intelligence/health', null, $st, 403);
    api('intelligence/agent/run', ['fixture_prompt' => 'test'], $st, 403);
    api('intelligence/health', null, $mt, 503);
    api('intelligence/forecast?sku[]=bad', null, $mt, 422);
    api('intelligence/recommendations?status[]=bad', null, $mt, 422);
    api('intelligence/agent/run', [], $mt, 422);
    api('intelligence/agent/run', ['message' => 'test'], $mt, 503);
    api('intelligence/agent/run', ['message' => 'test', 'unexpected' => true], $mt, 422);
    api('intelligence/results', null, null, 401);
    api('intelligence/results', null, $st, 503);
    api('intelligence/results', null, $mt, 503);
    check(R::count('purchaseorder') === 0 && R::count('movement') === 0, 'Intelligence requests do not mutate warehouse or create orders');
    api('workspace', null, $st, 403);
    api('procurement/run', [], $st, 403);
    api('procurement/approve', [], $st, 403);
    $found = api('lookup?code=001234567891', null, $st);
    check($found['product']['barcode'] === '001234567891', 'Barcode leading zeroes preserved');
    api('lookup?code=not-found', null, $st, 404);
    $p1 = $products[0];
    $baseMove = ['product_id' => $p1, 'warehouse_id' => $w1];
    $quantity = static fn(int $warehouse): int => (int)R::getCell('SELECT quantity_on_hand FROM stock WHERE product_id=? AND warehouse_id=?', [$p1, $warehouse]);
    $receive = $baseMove + ['type' => 'receive', 'quantity' => 5, 'request_id' => keyForRequest()];
    $m = api('movements', $receive, $st)['movement'];
    check($quantity($w1) === 25, 'Receiving increases actual stock');
    check(api('movements', $receive, $st)['movement']['id'] === $m['id'] && $quantity($w1) === 25, 'Retry does not duplicate movement');
    api('movements', array_replace($receive, ['quantity' => 7]), $st, 409);
    api('movements', $baseMove + ['type' => 'issue', 'quantity' => 100, 'request_id' => keyForRequest()], $st, 409);
    check($quantity($w1) === 25, 'Negative stock rejected without mutation');
    api('movements', $baseMove + ['type' => 'issue', 'quantity' => 3, 'request_id' => keyForRequest()], $st);
    api('movements', $baseMove + ['type' => 'transfer', 'quantity' => 4, 'destination_id' => $w2, 'request_id' => keyForRequest()], $st);
    check($quantity($w1) === 18 && $quantity($w2) === 4, 'Transfer updates both warehouses');
    api('movements', $baseMove + ['type' => 'transfer', 'quantity' => 1, 'destination_id' => $w2, 'order_id' => 999, 'request_id' => keyForRequest()], $st, 422);
    check($quantity($w1) === 18 && $quantity($w2) === 4, 'Failed transfer rolls back both sides');
    api('movements', $baseMove + ['type' => 'writeoff', 'quantity' => 2, 'request_id' => keyForRequest()], $st, 422);
    api('movements', $baseMove + ['type' => 'writeoff', 'quantity' => 2, 'note' => 'Damaged', 'request_id' => keyForRequest()], $st);
    $version = (int)R::getCell('SELECT version FROM stock WHERE product_id=? AND warehouse_id=?', [$p1, $w1]);
    api('movements', $baseMove + ['type' => 'count', 'quantity' => 15, 'expected_version' => $version - 1, 'note' => 'Count', 'request_id' => keyForRequest()], $st, 409);
    api('movements', $baseMove + ['type' => 'count', 'quantity' => 15, 'expected_version' => $version, 'note' => 'Count', 'request_id' => keyForRequest()], $st);
    check($quantity($w1) === 15, 'Count replaces balance and uses optimistic version');
    check(count(api('movements', null, $st)['movements']) === 5, 'Every successful operation is audited once');
    api('movements', ['product_id' => $products[1], 'warehouse_id' => $w2, 'type' => 'receive', 'quantity' => 2, 'request_id' => keyForRequest()], $st);
    check((int)R::getCell('SELECT quantity_on_hand FROM stock WHERE product_id=? AND warehouse_id=?', [$products[1], $w2]) === 2, 'First receiving creates a balance for a new warehouse');
    $run = api('procurement/run', [], $mt);
    check($run['source'] === 'mock' && R::count('purchaseorder') === 0, 'Calculation explicitly uses mock and never creates orders');
    $recommendations = api('recommendations', null, $mt)['recommendations'];
    $rec = current(array_filter($recommendations, fn($r) => (int)$r['product_id'] === $p1));
    $analysis = $rec['analysis'];
    $forecastId = (int)$rec['forecast_id'];
    api('forecast/analyze', ['forecast_id' => $forecastId], null, 401);
    api('forecast/analyze', ['forecast_id' => $forecastId], $st, 403);
    api('forecast/analyze', ['forecast_id' => 9999999], $mt, 404);
    $withoutKey = api('forecast/analyze', ['forecast_id' => $forecastId], $mt)['analysis'];
    check($withoutKey['source'] === 'calculation' && $withoutKey['reason'] === 'intelligence_service_required', 'Legacy endpoint explicitly requires Intelligence Service for new AI explanations');
    $snapshotJson = R::getCell('SELECT payload_json FROM forecast WHERE id=?', [$forecastId]);
    putenv('OPENAI_API_KEY=test-key-never-sent');
    $explained = (new ForecastService())->explain($forecastId);
    putenv('OPENAI_API_KEY=');
    check($explained['source'] === 'calculation' && !$explained['cached'], 'Even a legacy PHP key cannot trigger a direct OpenAI request');
    R::exec('UPDATE forecast SET ai_text=?, ai_source=?, ai_model=? WHERE id=?', ['Archived fixture explanation', 'openai', 'archived-model', $forecastId]);
    $cached = api('forecast/analyze', ['forecast_id' => $forecastId], $mt)['analysis'];
    check($cached['source'] === 'openai' && $cached['cached'] && $cached['text'] === 'Archived fixture explanation', 'Historical explanation remains readable without a new request');
    check(R::getCell('SELECT payload_json FROM forecast WHERE id=?', [$forecastId]) === $snapshotJson && R::count('purchaseorder') === 0 && R::getCell('SELECT status FROM recommendation WHERE id=?', [$rec['id']]) === 'draft', 'LLM explanation cannot modify forecast, confirm or create orders');
    check($analysis['lost_demand'] === 10 && $analysis['excluded_one_time_order'] === 100 && $analysis['stockout_days'] === 1, 'Mock removes one-time sales and restores lost demand');
    $service = new ForecastService();
    $input = current(array_filter($service->inputs(), fn($i) => $i['product_id'] === $p1 && $i['warehouse_id'] === $w1));
    $service->validate($analysis, $input);
    foreach ([['recommended_quantity' => -1], ['current_stock' => 999], ['seasonality_index' => '1.0'], ['horizon_days' => 0], ['seasonality_note' => null]] as $invalid) {
        try { $service->validate(array_replace($analysis, $invalid), $input); throw new RuntimeException('Bad ML response accepted'); }
        catch (ApiException $e) { check($e->status === 502, 'Malformed ML contract rejected'); }
    }
    api('movements', $baseMove + ['type' => 'issue', 'quantity' => 1, 'request_id' => keyForRequest()], $st);
    api('procurement/approve', ['request_id' => keyForRequest(), 'items' => [['id' => (int)$rec['id'], 'quantity' => 100, 'version' => 1]]], $mt, 409);
    check(R::count('purchaseorder') === 0, 'Stale recommendation cannot be approved');
    api('procurement/run', [], $mt);
    $recommendations = api('recommendations', null, $mt)['recommendations'];
    $items = array_map(fn($r) => ['id' => (int)$r['id'], 'quantity' => (int)$r['recommended_quantity'] + 5, 'version' => (int)$r['version']], $recommendations);
    $bad = $items; $bad[1]['quantity'] = 1;
    api('procurement/approve', ['request_id' => keyForRequest(), 'items' => $bad], $mt, 422);
    check(R::count('purchaseorder') === 0 && R::count('intransit') === 0, 'Approval batch rolls back on invalid minimum quantity');
    $approval = ['request_id' => keyForRequest(), 'items' => $items];
    $approved = api('procurement/approve', $approval, $mt);
    check(count($approved['orders']) === 3 && $quantity($w1) === 14, 'Explicit manager approval creates orders but not stock');
    check((int)R::getCell('SELECT COUNT(DISTINCT batch_id) FROM purchaseorder') === 2, 'Orders grouped into two supplier batches');
    check(api('procurement/approve', $approval, $mt)['orders'] === $approved['orders'] && R::count('purchaseorder') === 3, 'Approval retry is idempotent');
    $changed = $approval; $changed['items'][0]['quantity']++;
    api('procurement/approve', $changed, $mt, 409);
    api('procurement/approve', array_replace($approval, ['request_id' => keyForRequest()]), $mt, 409);
    $order = R::getRow('SELECT * FROM purchaseorder WHERE product_id=?', [$p1]);
    $approvedQty = (int)$order['suggested_qty'];
    $p1Rec = current(array_filter($recommendations, fn($r) => (int)$r['product_id'] === $p1));
    check($approvedQty === (int)$p1Rec['recommended_quantity'] + 5 && (int)$order['approved_by'] === (int)$manager['user']['id'], 'Manager quantity and approver are persisted');
    $receipt = $baseMove + ['type' => 'receive', 'quantity' => 3, 'order_id' => (int)$order['id'], 'request_id' => keyForRequest()];
    api('movements', $receipt, $st);
    $transit = R::getRow('SELECT * FROM intransit WHERE order_id=?', [$order['id']]);
    check($quantity($w1) === 17 && (int)$transit['received_qty'] === 3 && $transit['status'] === 'expected', 'Partial receipt reduces outstanding transit');
    api('movements', array_replace($receipt, ['quantity' => $approvedQty, 'request_id' => keyForRequest()]), $st, 409);
    api('movements', array_replace($receipt, ['quantity' => $approvedQty - 3, 'request_id' => keyForRequest()]), $st);
    check($quantity($w1) === 14 + $approvedQty && R::getCell('SELECT status FROM purchaseorder WHERE id=?', [$order['id']]) === 'received', 'Complete receipt closes order and reconciles stock');
    api('movements', array_replace($receipt, ['request_id' => keyForRequest()]), $st, 409);
    $beforeRecs = R::count('recommendation');
    putenv('INTELLIGENCE_API_URL=http://127.0.0.1:9');
    try { $service->run($manager['user']); throw new RuntimeException('Local calculation replaced intelligence'); }
    catch (ApiException $e) { check($e->status === 409 && R::count('recommendation') === $beforeRecs, 'Configured intelligence cannot silently use local demo procurement'); }
    putenv('INTELLIGENCE_API_URL=');
    putenv('ML_API_URL=' . $base . '/unavailable-ml');
    try { $service->run($manager['user']); throw new RuntimeException('ML failure incorrectly succeeded'); }
    catch (ApiException $e) { check($e->status === 502 && R::count('recommendation') === $beforeRecs, 'Unavailable ML keeps previous recommendations without silent mock fallback'); }
    putenv('ML_API_URL=');
    $workspace = api('workspace', null, $mt);
    check($workspace['runs'][0]['status'] === 'failed' && count($workspace['forecasts']) > 0, 'Workflow failure is visible, last successful forecasts preserved');
    api('suppliers', ['name' => 'New supplier', 'contact_info' => 'new@example.invalid', 'avg_lead_time_days' => 2], $mt, 201);
    $newSupplier = (int)R::getCell('SELECT id FROM supplier WHERE name=?', ['New supplier']);
    $newProduct = ['sku' => 'API-NEW', 'barcode' => '0001234567890', 'name' => 'New item', 'category' => 'New category', 'unit_cost' => 123.45, 'lead_time_days' => 2, 'min_order_qty' => 1, 'supplier_id' => $newSupplier];
    api('products', $newProduct, $st, 403);
    api('products', $newProduct, $mt, 201);
    api('products', $newProduct, $mt, 409);
    $productId = (int)R::getCell('SELECT id FROM product WHERE sku=?', ['API-NEW']);
    check((float)R::getCell('SELECT unit_cost FROM product WHERE id=?', [$productId]) === 123.45 && (int)R::getCell('SELECT COUNT(*) FROM stock WHERE product_id=?', [$productId]) === 2, 'New product preserves decimal price and starts with both warehouse balances');
    $sale = ['product_id' => $productId, 'warehouse_id' => $w1, 'date' => date('Y-m-d'), 'quantity' => 100, 'one_time_quantity' => 90, 'stockout' => false];
    api('sales', $sale, $mt);
    api('sales', array_replace($sale, ['quantity' => 110]), $mt);
    check((int)R::getCell('SELECT COUNT(*) FROM saleshistory WHERE product_id=?', [$productId]) === 1 && (int)R::getCell('SELECT quantity_on_hand FROM stock WHERE product_id=? AND warehouse_id=?', [$productId, $w1]) === 0, 'Historical sale correction replaces day without changing physical stock');
    api('sales', array_replace($sale, ['one_time_quantity' => 101]), $mt, 422);
    api('logout', [], $mt);
    api('session', null, $mt, 401);
    api('logout', [], $st);
    echo "\n{$checks} checks passed in an isolated database.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    R::close();
    // Only the randomly named database created by this test may be removed.
    if (preg_match('/^supplymind_test_[a-f0-9]{12}$/', $database)) { $admin->exec("DROP DATABASE IF EXISTS `{$database}`"); }
}
