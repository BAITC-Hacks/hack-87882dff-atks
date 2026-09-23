<?php
declare(strict_types=1);
namespace SmartStock;
use RedBeanPHP\R;

class InventoryService {
    public static function integer(mixed $value, string $name, int $min = 1): int {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > 10000000) { throw new ApiException("Некорректное значение: {$name}", 422); }
        return $number;
    }
    public static function locked(callable $action): mixed {
        if ((int)R::getCell('SELECT GET_LOCK(?, 10)', ['supplymind:inventory']) !== 1) { throw new ApiException('Склад обновляется. Повторите запрос.', 409); }
        try {
            R::begin();
            try { $result = $action(); R::commit(); return $result; }
            catch (\Throwable $e) { R::rollback(); throw $e; }
        } finally { R::getCell('SELECT RELEASE_LOCK(?)', ['supplymind:inventory']); }
    }
    public function catalog(): array {
        return [
            'products' => array_values(array_map('beanToArray', R::findAll('product', 'ORDER BY name'))),
            'categories' => array_values(array_map('beanToArray', R::findAll('category', 'ORDER BY name'))),
            'warehouses' => array_values(array_map('beanToArray', R::findAll('warehouse', 'ORDER BY id'))),
            'suppliers' => array_values(array_map('beanToArray', R::findAll('supplier', 'ORDER BY name'))),
            'supplier_products' => array_values(array_map('beanToArray', R::findAll('supplierproduct'))),
        ];
    }
    public function stock(): array {
        return R::getAll("SELECT s.*, p.sku, p.barcode, p.name, p.category, p.unit_cost, p.lead_time_days, w.name warehouse_name, w.location,
            COALESCE((SELECT SUM(t.quantity-t.received_qty) FROM intransit t WHERE t.product_id=s.product_id AND t.warehouse_id=s.warehouse_id AND t.status='expected'),0) in_transit
            FROM stock s JOIN product p ON p.id=s.product_id JOIN warehouse w ON w.id=s.warehouse_id ORDER BY p.name, w.id");
    }
    public function movements(): array {
        return R::getAll('SELECT m.*, p.name product_name, p.sku, w.name warehouse_name, d.name destination_name, u.name user_name FROM movement m JOIN product p ON p.id=m.product_id JOIN warehouse w ON w.id=m.warehouse_id LEFT JOIN warehouse d ON d.id=m.destination_id JOIN useraccount u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 200');
    }
    public function move(array $body, array $user): array {
        $type = (string)($body['type'] ?? '');
        if (!in_array($type, ['receive', 'issue', 'transfer', 'writeoff', 'count'], true)) { throw new ApiException('Неизвестная операция', 422); }
        $productId = self::integer($body['product_id'] ?? null, 'товар');
        $warehouseId = self::integer($body['warehouse_id'] ?? null, 'склад');
        $quantity = self::integer($body['quantity'] ?? null, 'количество', $type === 'count' ? 0 : 1);
        $destinationId = $type === 'transfer' ? self::integer($body['destination_id'] ?? null, 'склад назначения') : null;
        if ($destinationId === $warehouseId) { throw new ApiException('Выберите другой склад назначения', 422); }
        $key = (string)($body['request_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $key)) { throw new ApiException('Требуется request_id операции', 422); }
        $key = $user['id'] . ':' . $key;
        $hash = hash('sha256', json_encode($body));
        return self::locked(function () use ($body, $user, $type, $productId, $warehouseId, $quantity, $destinationId, $key, $hash) {
            $existing = R::getRow('SELECT * FROM movement WHERE request_key = ?', [$key]);
            if ($existing) {
                if ($existing['payload_hash'] !== $hash) { throw new ApiException('Идентификатор уже использован для другой операции', 409); }
                return $existing;
            }
            $stock = R::getRow('SELECT * FROM stock WHERE product_id = ? AND warehouse_id = ? FOR UPDATE', [$productId, $warehouseId]);
            if (!$stock && $type === 'receive' && R::load('product', $productId)->id && R::load('warehouse', $warehouseId)->id) {
                R::exec('INSERT INTO stock (product_id, warehouse_id, quantity_on_hand, safety_stock, reorder_point, version) VALUES (?, ?, 0, 0, 0, 1)', [$productId, $warehouseId]);
                $stock = R::getRow('SELECT * FROM stock WHERE product_id=? AND warehouse_id=? FOR UPDATE', [$productId, $warehouseId]);
            }
            if (!$stock) { throw new ApiException('Товар на этом складе не найден', 404); }
            $before = (int)$stock['quantity_on_hand'];
            if ($type === 'count' && (int)($body['expected_version'] ?? 0) !== (int)$stock['version']) { throw new ApiException('Остаток изменился. Обновите данные перед инвентаризацией.', 409); }
            $after = match($type) { 'receive' => $before + $quantity, 'count' => $quantity, default => $before - $quantity };
            if ($after < 0) { throw new ApiException('Недостаточно товара на складе', 409); }
            $note = trim((string)($body['note'] ?? ''));
            if (in_array($type, ['writeoff', 'count'], true) && $note === '') { throw new ApiException('Укажите причину корректировки', 422); }
            $destinationAfter = null;
            if ($destinationId) {
                if (!R::load('warehouse', $destinationId)->id) { throw new ApiException('Склад назначения не найден', 404); }
                $destination = R::getRow('SELECT * FROM stock WHERE product_id = ? AND warehouse_id = ? FOR UPDATE', [$productId, $destinationId]);
                if (!$destination) {
                    R::exec('INSERT INTO stock (product_id, warehouse_id, quantity_on_hand, safety_stock, reorder_point, version) VALUES (?, ?, 0, 0, 0, 1)', [$productId, $destinationId]);
                    $destination = R::getRow('SELECT * FROM stock WHERE product_id = ? AND warehouse_id = ?', [$productId, $destinationId]);
                }
                $destinationAfter = (int)$destination['quantity_on_hand'] + $quantity;
                R::exec('UPDATE stock SET quantity_on_hand = ?, version = version + 1 WHERE id = ?', [$destinationAfter, $destination['id']]);
            }
            $orderId = !empty($body['order_id']) ? self::integer($body['order_id'], 'заказ') : null;
            if ($orderId) {
                if ($type !== 'receive') { throw new ApiException('Заказ можно указать только для приёмки', 422); }
                $transit = R::getRow('SELECT * FROM intransit WHERE order_id = ? AND status = ? FOR UPDATE', [$orderId, 'expected']);
                if (!$transit || (int)$transit['product_id'] !== $productId || (int)$transit['warehouse_id'] !== $warehouseId || $quantity > (int)$transit['quantity'] - (int)$transit['received_qty']) {
                    throw new ApiException('Приёмка не соответствует ожидаемой поставке', 409);
                }
                $received = (int)$transit['received_qty'] + $quantity;
                $complete = $received === (int)$transit['quantity'];
                R::exec('UPDATE intransit SET received_qty = ?, status = ? WHERE id = ?', [$received, $complete ? 'received' : 'expected', $transit['id']]);
                R::exec('UPDATE purchaseorder SET received_qty = ?, status = ? WHERE id = ?', [$received, $complete ? 'received' : 'approved', $orderId]);
            }
            R::exec('UPDATE stock SET quantity_on_hand = ?, version = version + 1 WHERE id = ?', [$after, $stock['id']]);
            R::exec('INSERT INTO movement (request_key, payload_hash, product_id, warehouse_id, destination_id, order_id, user_id, type, quantity, before_qty, after_qty, destination_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [$key, $hash, $productId, $warehouseId, $destinationId, $orderId, $user['id'], $type, $quantity, $before, $after, $destinationAfter, $note]);
            return R::getRow('SELECT * FROM movement WHERE request_key = ?', [$key]);
        });
    }
}
