<?php
declare(strict_types=1);
namespace SmartStock;
use RedBeanPHP\R;

class ForecastService {
    public function inputs(): array {
        $inputs = [];
        foreach ((new InventoryService())->stock() as $stock) {
            $supplier = R::getRow('SELECT sp.supplier_id id, s.name, sp.unit_price, sp.min_order_qty FROM supplierproduct sp JOIN supplier s ON s.id=sp.supplier_id WHERE sp.product_id=? ORDER BY sp.unit_price, sp.id LIMIT 1', [$stock['product_id']]);
            if (!$supplier) { continue; }
            $history = R::getAll('SELECT date, SUM(quantity_sold) quantity_sold, MAX(stockout) stockout, SUM(one_time_quantity) one_time_quantity FROM saleshistory WHERE product_id=? AND warehouse_id=? AND date BETWEEN ? AND ? GROUP BY date ORDER BY date', [$stock['product_id'], $stock['warehouse_id'], date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]);
            $inputs[] = [
                'product_id' => (int)$stock['product_id'], 'warehouse_id' => (int)$stock['warehouse_id'],
                'sku' => (string)$stock['sku'], 'product_name' => $stock['name'], 'warehouse_name' => $stock['warehouse_name'],
                'current_stock' => (int)$stock['quantity_on_hand'], 'stock_version' => (int)$stock['version'],
                'in_transit' => (int)$stock['in_transit'], 'lead_time_days' => max(1, (int)$stock['lead_time_days']),
                'safety_stock' => (float)$stock['safety_stock'],
                'supplier' => ['id' => (int)$supplier['id'], 'name' => $supplier['name'], 'unit_price' => (float)$supplier['unit_price'], 'min_order_qty' => (int)$supplier['min_order_qty']],
                'sales_history' => array_map(static fn($day) => ['date' => $day['date'], 'quantity_sold' => (int)$day['quantity_sold'], 'stockout' => (bool)$day['stockout'], 'one_time_quantity' => (int)$day['one_time_quantity']], $history),
            ];
        }
        return $inputs;
    }

    public function mock(array $input): array {
        // Детерминированный mock контракта, не обученная ML-модель.
        $clean = array_values(array_filter($input['sales_history'], static fn($day) => !$day['stockout']));
        $values = array_map(static fn($day) => max(0, $day['quantity_sold'] - $day['one_time_quantity']), $clean);
        $baseline = count($values) ? array_sum($values) / count($values) : 0;
        $last = array_slice($values, -7);
        $previous = array_slice($values, 0, max(0, count($values) - 7));
        $recent = count($last) ? array_sum($last) / count($last) : 0;
        $before = count($previous) ? array_sum($previous) / count($previous) : $recent;
        $growth = $before > 0 ? ($recent / $before - 1) * 100 : 0;
        $stockouts = count(array_filter($input['sales_history'], static fn($day) => $day['stockout']));
        $lost = (int)ceil($baseline * $stockouts);
        $excluded = array_sum(array_column($input['sales_history'], 'one_time_quantity'));
        $horizon = $input['lead_time_days'] + 7;
        $forecast = (int)ceil($recent * $horizon);
        $raw = max(0, (int)ceil($forecast + $input['safety_stock'] - $input['current_stock'] - $input['in_transit']));
        $qty = $raw > 0 && count($values) > 0 ? max($raw, $input['supplier']['min_order_qty']) : 0;
        $coverage = $recent > 0 ? $input['current_stock'] / $recent : null;
        $urgency = $qty === 0 ? 'none' : ($coverage !== null && $coverage < $input['lead_time_days'] ? 'critical' : 'normal');
        $explanation = count($values) === 0 ? 'Недостаточно истории продаж для рекомендации. Заказ не сформирован.' : sprintf('За %d дн. ожидается спрос %d шт.; на складе %d шт., в пути %d шт., резерв %s шт. Рекомендуется %d шт. с учётом минимальной партии поставщика. Восстановлено %d шт. упущенного спроса, исключено %d шт. разовых продаж.', $horizon, $forecast, $input['current_stock'], $input['in_transit'], $input['safety_stock'], $qty, $lost, $excluded);
        return [
            'sku' => $input['sku'], 'warehouse_id' => $input['warehouse_id'], 'current_stock' => $input['current_stock'], 'in_transit' => $input['in_transit'],
            'forecast_demand' => $forecast, 'lost_demand' => $lost, 'excluded_one_time_order' => $excluded, 'stockout_days' => $stockouts,
            'supplier' => $input['supplier'], 'recommended_quantity' => $qty, 'urgency' => $urgency, 'explanation' => $explanation,
            'horizon_days' => $horizon, 'lead_time_days' => $input['lead_time_days'], 'seasonality_index' => 1.0,
            'seasonality_note' => 'В mock сезонность нейтральна; коэффициент 1,00. Оценка модели появится после подключения ML API.',
            'demand_growth_percent' => round($growth, 2), 'average_daily_demand' => round($recent, 2),
        ];
    }

    public function validate(array $result, array $input): void {
        if (($result['sku'] ?? null) !== $input['sku'] || ($result['warehouse_id'] ?? null) !== $input['warehouse_id']) { throw new ApiException('ML API вернул неизвестный товар или склад', 502); }
        foreach (['current_stock', 'in_transit', 'forecast_demand', 'lost_demand', 'excluded_one_time_order', 'stockout_days', 'recommended_quantity', 'horizon_days', 'lead_time_days'] as $field) {
            if (!isset($result[$field]) || !is_int($result[$field]) || $result[$field] < 0 || $result[$field] > 10000000) { throw new ApiException("Нарушен ML-контракт: {$field}", 502); }
        }
        foreach (['seasonality_index', 'demand_growth_percent', 'average_daily_demand'] as $field) {
            if (!isset($result[$field]) || (!is_int($result[$field]) && !is_float($result[$field])) || !is_finite((float)$result[$field])) { throw new ApiException("Нарушен ML-контракт: {$field}", 502); }
        }
        if ($result['seasonality_index'] < 0 || $result['average_daily_demand'] < 0 || $result['stockout_days'] > count($input['sales_history']) || $result['horizon_days'] < 1 || $result['lead_time_days'] !== $input['lead_time_days']) { throw new ApiException('Некорректные параметры прогноза ML', 502); }
        if (!is_string($result['seasonality_note'] ?? null) || trim($result['seasonality_note']) === '' || strlen($result['seasonality_note']) > 8000) { throw new ApiException('ML API не объяснил сезонность', 502); }
        if (!in_array($result['urgency'] ?? '', ['critical', 'normal', 'none'], true) || !is_string($result['explanation'] ?? null) || trim($result['explanation']) === '' || strlen($result['explanation']) > 8000) { throw new ApiException('ML API вернул некорректное объяснение или срочность', 502); }
        if ($result['current_stock'] !== $input['current_stock'] || $result['in_transit'] !== $input['in_transit'] || ($result['supplier']['id'] ?? null) !== $input['supplier']['id']) { throw new ApiException('ML API изменил исходные остатки или поставщика', 502); }
        if ($result['recommended_quantity'] > 0 && $result['recommended_quantity'] < $input['supplier']['min_order_qty']) { throw new ApiException('Рекомендация ML ниже минимальной партии', 502); }
    }

    public function run(array $user): array {
        if (trim((string)getenv('INTELLIGENCE_API_URL')) !== '') {
            throw new ApiException('Intelligence Service настроен. Импорт его рекомендаций в закупки требует согласованного Swagger-контракта; локальный перерасчёт отключён.', 409);
        }
        $source = trim((string)getenv('ML_API_URL')) !== '' ? 'fastapi' : 'mock';
        R::exec('INSERT INTO forecastrun (user_id, source, status, steps_json, created_at) VALUES (?, ?, ?, ?, NOW())', [$user['id'], $source, 'running', '[]']);
        $runId = (int)R::getCell('SELECT LAST_INSERT_ID()');
        $steps = [];
        try {
            $inputs = $this->inputs();
            if (!$inputs) { throw new ApiException('Нет товаров с поставщиками для расчёта', 422); }
            $steps[] = ['name' => 'Загрузка остатков, поставок и продаж', 'status' => 'completed', 'detail' => count($inputs) . ' складских позиций', 'at' => date(DATE_ATOM)];
            if ($source === 'mock') {
                $results = array_map(fn($input) => $this->mock($input), $inputs);
            } else {
                $url = rtrim((string)getenv('ML_API_URL'), '/') . '/forecast';
                $ch = curl_init($url);
                $headers = ['Content-Type: application/json'];
                if (getenv('ML_API_KEY')) { $headers[] = 'Authorization: Bearer ' . getenv('ML_API_KEY'); }
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode(['contract_version' => '1.0', 'items' => $inputs], JSON_UNESCAPED_UNICODE)]);
                $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($raw === false || $code !== 200) { throw new ApiException('ML API недоступен. Предыдущие рекомендации сохранены.', 502); }
                $payload = json_decode($raw, true);
                if (($payload['contract_version'] ?? '') !== '1.0' || !is_array($payload['recommendations'] ?? null)) { throw new ApiException('Неверная версия или структура ML-контракта', 502); }
                $results = $payload['recommendations'];
            }
            $steps[] = ['name' => 'Прогноз и корректировка спроса', 'status' => 'completed', 'detail' => $source === 'mock' ? 'Mock-адаптер · без обученной модели' : 'Ответ Python ML API получен', 'at' => date(DATE_ATOM)];
            if (count($results) !== count($inputs)) { throw new ApiException('ML API вернул неполный набор прогнозов', 502); }
            $map = []; foreach ($inputs as $input) { $map[$input['sku'] . ':' . $input['warehouse_id']] = $input; }
            $seen = [];
            foreach ($results as $result) {
                if (!is_array($result)) { throw new ApiException('ML API вернул некорректную запись', 502); }
                $key = ($result['sku'] ?? '') . ':' . ($result['warehouse_id'] ?? '');
                if (!isset($map[$key]) || isset($seen[$key])) { throw new ApiException('ML API вернул неизвестную или повторную позицию', 502); }
                $seen[$key] = true; $this->validate($result, $map[$key]);
            }
            $steps[] = ['name' => 'Проверка контракта и группировка', 'status' => 'completed', 'detail' => 'Версия 1.0 · поставщики проверены', 'at' => date(DATE_ATOM)];
            InventoryService::locked(function () use ($results, $map, $runId) {
                R::exec('UPDATE recommendation SET status = ? WHERE status = ?', ['superseded', 'draft']);
                foreach ($results as $result) {
                    $input = $map[$result['sku'] . ':' . $result['warehouse_id']];
                    $result['supplier'] = $input['supplier'];
                    R::exec('INSERT INTO forecast (run_id, product_id, warehouse_id, payload_json, input_hash, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$runId, $input['product_id'], $input['warehouse_id'], json_encode($result, JSON_UNESCAPED_UNICODE), hash('sha256', json_encode($input))]);
                    $forecastId = (int)R::getCell('SELECT LAST_INSERT_ID()');
                    if ($result['recommended_quantity'] > 0) {
                        R::exec('INSERT INTO recommendation (forecast_id, run_id, product_id, warehouse_id, supplier_id, recommended_quantity) VALUES (?, ?, ?, ?, ?, ?)', [$forecastId, $runId, $input['product_id'], $input['warehouse_id'], $input['supplier']['id'], $result['recommended_quantity']]);
                    }
                }
            });
            $steps[] = ['name' => 'Передача менеджеру', 'status' => 'completed', 'detail' => 'Рекомендации сохранены. Заказы не отправлялись.', 'at' => date(DATE_ATOM)];
            R::exec('UPDATE forecastrun SET status=?, steps_json=?, completed_at=NOW() WHERE id=?', ['completed', json_encode($steps, JSON_UNESCAPED_UNICODE), $runId]);
        } catch (\Throwable $e) {
            $message = $e instanceof ApiException ? $e->getMessage() : 'Ошибка расчёта. Повторите попытку.';
            $steps[] = ['name' => 'Расчёт остановлен', 'status' => 'failed', 'detail' => $message, 'at' => date(DATE_ATOM)];
            R::exec('UPDATE forecastrun SET status=?, error=?, steps_json=?, completed_at=NOW() WHERE id=?', ['failed', $message, json_encode($steps, JSON_UNESCAPED_UNICODE), $runId]);
            throw new ApiException($message, $e instanceof ApiException ? $e->status : 500);
        }
        return ['run_id' => $runId, 'source' => $source, 'steps' => $steps];
    }

    public function recommendations(): array {
        $rows = R::getAll('SELECT r.*, f.payload_json, f.ai_text, f.ai_source, f.ai_model, f.ai_created_at, p.name product_name, w.name warehouse_name, fr.source FROM recommendation r JOIN forecast f ON f.id=r.forecast_id JOIN product p ON p.id=r.product_id JOIN warehouse w ON w.id=r.warehouse_id JOIN forecastrun fr ON fr.id=r.run_id WHERE r.status IN (?, ?) ORDER BY r.id DESC', ['draft', 'approved']);
        return array_map(static function ($row) { $row['analysis'] = json_decode($row['payload_json'], true); unset($row['payload_json']); return $row; }, $rows);
    }

    public function explain(int $id): array {
        $lock = 'supplymind:explanation:' . $id;
        if ((int)R::getCell('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) { throw new ApiException('Объяснение уже запрашивается. Повторите позже.', 409); }
        try {
            $row = R::getRow('SELECT f.*, p.name product_name, fr.source FROM forecast f JOIN product p ON p.id=f.product_id JOIN forecastrun fr ON fr.id=f.run_id WHERE f.id=?', [$id]);
            if (!$row) { throw new ApiException('Расчёт не найден', 404); }
            if ($row['ai_source'] === 'openai' && trim((string)$row['ai_text']) !== '') {
                return ['text' => $row['ai_text'], 'source' => 'openai', 'reason' => null, 'model' => $row['ai_model'], 'cached' => true, 'message' => 'Ранее сохранённое объяснение OpenAI; новый запрос не выполнялся.'];
            }
            $analysis = (new AiExplainer())->analyzeForecast($row['product_name'], json_decode($row['payload_json'], true), $row['source']);
            return $analysis + ['cached' => false, 'message' => AiExplainer::reasonMessage($analysis['reason'])];
        } finally { R::getCell('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public function approve(array $body, array $user): array {
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) < 1 || count($items) > 100) { throw new ApiException('Выберите от 1 до 100 рекомендаций', 422); }
        $key = (string)($body['request_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $key)) { throw new ApiException('Требуется request_id подтверждения', 422); }
        $key = $user['id'] . ':' . $key; $hash = hash('sha256', json_encode($items));
        return InventoryService::locked(function () use ($items, $key, $hash, $user) {
            $existing = R::getRow('SELECT * FROM approvalrequest WHERE request_key=?', [$key]);
            if ($existing) {
                if ($existing['payload_hash'] !== $hash) { throw new ApiException('Идентификатор подтверждения уже использован', 409); }
                return json_decode($existing['result_json'], true);
            }
            $inputs = []; foreach ($this->inputs() as $input) { $inputs[$input['product_id'] . ':' . $input['warehouse_id']] = $input; }
            $orders = []; $seen = []; $batch = bin2hex(random_bytes(8));
            foreach ($items as $item) {
                $id = InventoryService::integer($item['id'] ?? null, 'рекомендация');
                if (isset($seen[$id])) { throw new ApiException('Рекомендация указана дважды', 422); } $seen[$id] = true;
                $quantity = InventoryService::integer($item['quantity'] ?? null, 'количество');
                $row = R::getRow('SELECT r.*, f.payload_json, f.input_hash FROM recommendation r JOIN forecast f ON f.id=r.forecast_id WHERE r.id=? FOR UPDATE', [$id]);
                if (!$row) { throw new ApiException('Рекомендация не найдена', 404); }
                if ($row['status'] !== 'draft' || (int)$row['version'] !== (int)($item['version'] ?? 0)) { throw new ApiException('Рекомендация уже изменена. Обновите список.', 409); }
                $input = $inputs[$row['product_id'] . ':' . $row['warehouse_id']] ?? null;
                if (!$input || hash('sha256', json_encode($input)) !== $row['input_hash']) { throw new ApiException('Остатки, поставки или продажи изменились. Запустите расчёт заново.', 409); }
                if ($quantity < $input['supplier']['min_order_qty']) { throw new ApiException('Количество меньше минимальной партии поставщика', 422); }
                $analysis = json_decode($row['payload_json'], true);
                $group = $batch . '-' . $row['supplier_id'];
                R::exec('INSERT INTO purchaseorder (supplier_id, product_id, warehouse_id, suggested_qty, status, ai_explanation, created_at, unit_price, ai_source, recommendation_id, batch_id, approved_by, approved_at, received_qty) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, NOW(), 0)', [$row['supplier_id'], $row['product_id'], $row['warehouse_id'], $quantity, 'approved', $analysis['explanation'], $input['supplier']['unit_price'], 'forecast', $id, $group, $user['id']]);
                $orderId = (int)R::getCell('SELECT LAST_INSERT_ID()');
                R::exec('INSERT INTO intransit (order_id, product_id, warehouse_id, quantity, expected_at) VALUES (?, ?, ?, ?, ?)', [$orderId, $row['product_id'], $row['warehouse_id'], $quantity, date('Y-m-d', strtotime('+' . $input['lead_time_days'] . ' days'))]);
                R::exec('UPDATE recommendation SET status=?, manager_quantity=?, approved_by=?, approved_at=NOW(), version=version+1 WHERE id=?', ['approved', $quantity, $user['id'], $id]);
                $orders[] = ['id' => $orderId, 'supplier_id' => (int)$row['supplier_id'], 'batch_id' => $group, 'quantity' => $quantity];
            }
            $result = ['orders' => $orders, 'message' => 'Заказы подтверждены менеджером. Автоматическая отправка поставщику не выполнялась.'];
            R::exec('INSERT INTO approvalrequest (request_key, payload_hash, result_json) VALUES (?, ?, ?)', [$key, $hash, json_encode($result, JSON_UNESCAPED_UNICODE)]);
            return $result;
        });
    }
}
