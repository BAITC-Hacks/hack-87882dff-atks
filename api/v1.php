<?php
declare(strict_types=1);

use RedBeanPHP\R;
use SmartStock\ApiException;
use SmartStock\Auth;
use SmartStock\ForecastService;
use SmartStock\InventoryService;

require_once __DIR__ . '/../config.php';
handlePreflight();
$route = substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/api/v1/'));
$method = $_SERVER['REQUEST_METHOD'];
try {
    connectDb();
    R::freeze(true);
    if ($route === 'login' && $method === 'POST') { jsonResponse(['success' => true] + Auth::login(readJsonBody())); }
    $user = Auth::user();
    $inventory = new InventoryService();
    $forecasts = new ForecastService();
    if ($route === 'session' && $method === 'GET') { jsonResponse(['success' => true, 'user' => $user]); }
    if ($route === 'logout' && $method === 'POST') { Auth::logout(); jsonResponse(['success' => true]); }
    if ($route === 'intelligence/results' && $method === 'GET') {
        jsonResponse(['success' => true, 'data' => (new \SmartStock\IntelligenceResultsService())->get(($_GET['refresh'] ?? '') === '1')]);
    }
    if ($route === 'catalog' && $method === 'GET') { jsonResponse(['success' => true] + $inventory->catalog()); }
    if ($route === 'inventory' && $method === 'GET') { jsonResponse(['success' => true, 'items' => $inventory->stock()]); }
    if ($route === 'movements' && $method === 'GET') { jsonResponse(['success' => true, 'movements' => $inventory->movements()]); }
    if ($route === 'movements' && $method === 'POST') { jsonResponse(['success' => true, 'movement' => $inventory->move(readJsonBody(), $user)]); }
    if ($route === 'lookup' && $method === 'GET') {
        $code = trim((string)($_GET['code'] ?? ''));
        $product = R::findOne('product', 'sku=? OR barcode=?', [$code, $code]);
        if (!$product) { throw new ApiException('Товар с таким кодом не найден', 404); }
        $stocks = array_values(array_filter($inventory->stock(), fn($stock) => (int)$stock['product_id'] === (int)$product->id));
        jsonResponse(['success' => true, 'product' => beanToArray($product), 'stocks' => $stocks]);
    }
    if ($route === 'purchase-orders' && $method === 'GET') {
        $orders = R::getAll('SELECT o.*, p.name product_name, p.sku, s.name supplier_name, w.name warehouse_name, t.expected_at FROM purchaseorder o JOIN product p ON p.id=o.product_id JOIN supplier s ON s.id=o.supplier_id JOIN warehouse w ON w.id=o.warehouse_id LEFT JOIN intransit t ON t.order_id=o.id WHERE o.status IN (?, ?) ORDER BY o.id DESC', ['approved', 'received']);
        jsonResponse(['success' => true, 'orders' => $orders]);
    }
    Auth::user('manager');
    if (str_starts_with($route, 'intelligence/')) { require __DIR__ . '/intelligence.php'; }
    if ($route === 'forecast/analyze' && $method === 'POST') {
        $body = readJsonBody();
        $id = InventoryService::integer($body['forecast_id'] ?? null, 'расчёт');
        jsonResponse(['success' => true, 'analysis' => $forecasts->explain($id)]);
    }
    if ($route === 'procurement/run' && $method === 'POST') { jsonResponse(['success' => true] + $forecasts->run($user)); }
    if ($route === 'procurement/approve' && $method === 'POST') { jsonResponse(['success' => true] + $forecasts->approve(readJsonBody(), $user)); }
    if ($route === 'recommendations' && $method === 'GET') { jsonResponse(['success' => true, 'recommendations' => $forecasts->recommendations()]); }
    if ($route === 'workspace' && $method === 'GET') {
        $runs = R::getAll('SELECT * FROM forecastrun ORDER BY id DESC LIMIT 20');
        foreach ($runs as &$run) { $run['steps'] = json_decode($run['steps_json'], true); unset($run['steps_json']); } unset($run);
        $latest = R::getCell('SELECT id FROM forecastrun WHERE status=? ORDER BY id DESC LIMIT 1', ['completed']);
        $forecastRows = $latest ? R::getAll('SELECT f.*, p.name product_name, w.name warehouse_name, fr.source FROM forecast f JOIN product p ON p.id=f.product_id JOIN warehouse w ON w.id=f.warehouse_id JOIN forecastrun fr ON fr.id=f.run_id WHERE f.run_id=?', [$latest]) : [];
        foreach ($forecastRows as &$forecast) { $forecast['analysis'] = json_decode($forecast['payload_json'], true); unset($forecast['payload_json']); } unset($forecast);
        $sales = R::getAll('SELECT h.*, p.name product_name, p.sku, w.name warehouse_name FROM saleshistory h JOIN product p ON p.id=h.product_id JOIN warehouse w ON w.id=h.warehouse_id WHERE h.date BETWEEN ? AND ? ORDER BY h.date DESC, h.id DESC', [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]);
        jsonResponse(['success' => true] + $inventory->catalog() + ['inventory' => $inventory->stock(), 'movements' => $inventory->movements(), 'recommendations' => $forecasts->recommendations(), 'forecasts' => $forecastRows, 'runs' => $runs, 'sales' => $sales, 'ml_source' => getenv('INTELLIGENCE_API_URL') ? 'intelligence' : (getenv('ML_API_URL') ? 'fastapi' : 'mock')]);
    }
    if ($route === 'sales' && $method === 'POST') {
        $body = readJsonBody();
        $productId = InventoryService::integer($body['product_id'] ?? null, 'товар');
        $warehouseId = InventoryService::integer($body['warehouse_id'] ?? null, 'склад');
        $qty = InventoryService::integer($body['quantity'] ?? null, 'продажи', 0);
        $oneTime = InventoryService::integer($body['one_time_quantity'] ?? 0, 'разовый заказ', 0);
        $date = (string)($body['date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d') || $oneTime > $qty) { throw new ApiException('Проверьте дату и разовое количество', 422); }
        if (!R::load('product', $productId)->id || !R::load('warehouse', $warehouseId)->id) { throw new ApiException('Товар или склад не найден', 404); }
        InventoryService::locked(function () use ($productId, $warehouseId, $date, $qty, $oneTime, $body) {
            R::exec('DELETE FROM saleshistory WHERE product_id=? AND warehouse_id=? AND date=?', [$productId, $warehouseId, $date]);
            R::exec('INSERT INTO saleshistory (product_id, warehouse_id, date, quantity_sold, stockout, one_time_quantity) VALUES (?, ?, ?, ?, ?, ?)', [$productId, $warehouseId, $date, $qty, !empty($body['stockout']) ? 1 : 0, $oneTime]);
        });
        jsonResponse(['success' => true]);
    }
    if ($route === 'products' && $method === 'POST') {
        $body = readJsonBody();
        foreach (['sku', 'name', 'category', 'barcode'] as $field) { if (trim((string)($body[$field] ?? '')) === '') { throw new ApiException('Заполните все поля товара', 422); } }
        if (R::findOne('product', 'sku=? OR barcode=?', [$body['sku'], $body['barcode']])) { throw new ApiException('Артикул или штрихкод уже используется', 409); }
        $supplierId = InventoryService::integer($body['supplier_id'] ?? null, 'поставщик');
        if (!R::load('supplier', $supplierId)->id) { throw new ApiException('Поставщик не найден', 404); }
        $lead = InventoryService::integer($body['lead_time_days'] ?? null, 'срок поставки');
        $minimum = InventoryService::integer($body['min_order_qty'] ?? 1, 'минимальная партия');
        $cost = filter_var($body['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($cost === false || $cost < 0 || $cost > 100000000) { throw new ApiException('Некорректная цена', 422); }
        InventoryService::locked(function () use ($body, $supplierId, $lead, $minimum, $cost) {
            if (R::findOne('product', 'sku=? OR barcode=?', [$body['sku'], $body['barcode']])) { throw new ApiException('Артикул или штрихкод уже используется', 409); }
            R::exec('INSERT IGNORE INTO category (name) VALUES (?)', [$body['category']]);
            $categoryId = R::getCell('SELECT id FROM category WHERE name=?', [$body['category']]);
            R::exec('INSERT INTO product (sku, name, category, barcode, category_id, unit_cost, lead_time_days) VALUES (?, ?, ?, ?, ?, ?, ?)', [$body['sku'], $body['name'], $body['category'], $body['barcode'], $categoryId, $cost, $lead]);
            $id = R::getCell('SELECT LAST_INSERT_ID()');
            R::exec('INSERT INTO supplierproduct (supplier_id, product_id, unit_price, min_order_qty) VALUES (?, ?, ?, ?)', [$supplierId, $id, $cost, $minimum]);
            foreach (R::findAll('warehouse') as $warehouse) { R::exec('INSERT INTO stock (product_id, warehouse_id, quantity_on_hand, safety_stock, reorder_point, version) VALUES (?, ?, 0, 0, 0, 1)', [$id, $warehouse->id]); }
        });
        jsonResponse(['success' => true], 201);
    }
    if ($route === 'suppliers' && $method === 'POST') {
        $body = readJsonBody();
        if (trim((string)($body['name'] ?? '')) === '' || trim((string)($body['contact_info'] ?? '')) === '') { throw new ApiException('Укажите название и контакт поставщика', 422); }
        $lead = InventoryService::integer($body['avg_lead_time_days'] ?? null, 'срок поставки');
        R::exec('INSERT INTO supplier (name, contact_info, avg_lead_time_days) VALUES (?, ?, ?)', [$body['name'], $body['contact_info'], $lead]);
        jsonResponse(['success' => true], 201);
    }
    throw new ApiException('Маршрут или метод не поддерживается', 404);
} catch (ApiException $e) { errorResponse($e->getMessage(), $e->status); }
catch (Throwable $e) { error_log('SupplyMind: ' . $e->getMessage()); errorResponse('Не удалось выполнить запрос. Проверьте соединение с сервером.', 500); }
