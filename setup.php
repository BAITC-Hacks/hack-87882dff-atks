<?php
declare(strict_types=1);

use RedBeanPHP\R;

require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') { exit; }
connectDb();

// Добавляем схему без очистки товаров, продаж и заказов предыдущей версии.
function addColumn(string $table, string $column, string $definition): void {
    if (!array_key_exists($column, R::inspect($table))) {
        R::exec("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
    }
}
$tables = [
    'product' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(100) NOT NULL, name VARCHAR(190) NOT NULL, category VARCHAR(190) NOT NULL, unit_cost DOUBLE NOT NULL DEFAULT 0, lead_time_days INT NOT NULL DEFAULT 1, base_sales INT NULL',
    'warehouse' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, location VARCHAR(190) NOT NULL',
    'stock' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, quantity_on_hand INT NOT NULL DEFAULT 0, safety_stock DOUBLE NOT NULL DEFAULT 0, reorder_point DOUBLE NOT NULL DEFAULT 0, UNIQUE KEY stock_pair(product_id, warehouse_id)',
    'supplier' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, contact_info VARCHAR(255) NOT NULL, avg_lead_time_days INT NOT NULL DEFAULT 1',
    'supplierproduct' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, unit_price DOUBLE NOT NULL DEFAULT 0, min_order_qty INT NOT NULL DEFAULT 1',
    'saleshistory' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, date DATE NOT NULL, quantity_sold INT NOT NULL DEFAULT 0',
    'purchaseorder' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, suggested_qty INT NOT NULL, status VARCHAR(30) NOT NULL, ai_explanation TEXT NULL, created_at DATETIME NOT NULL',
    'category' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE',
    'useraccount' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL',
    'apitoken' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL',
    'movement' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(100) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, destination_id INT UNSIGNED NULL, order_id INT UNSIGNED NULL, user_id INT UNSIGNED NOT NULL, type VARCHAR(20) NOT NULL, quantity INT NOT NULL, before_qty INT NOT NULL, after_qty INT NOT NULL, destination_after INT NULL, note TEXT, created_at DATETIME NOT NULL',
    'intransit' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NULL UNIQUE, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, quantity INT NOT NULL, received_qty INT NOT NULL DEFAULT 0, expected_at DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'expected\'',
    'forecastrun' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, source VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, steps_json LONGTEXT NOT NULL, error TEXT NULL, created_at DATETIME NOT NULL, completed_at DATETIME NULL',
    'forecast' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, payload_json LONGTEXT NOT NULL, input_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL',
    'recommendation' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, forecast_id INT UNSIGNED NOT NULL, run_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, warehouse_id INT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, recommended_quantity INT NOT NULL, manager_quantity INT NULL, status VARCHAR(20) NOT NULL DEFAULT \'draft\', version INT NOT NULL DEFAULT 1, approved_by INT UNSIGNED NULL, approved_at DATETIME NULL',
    'approvalrequest' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_key VARCHAR(100) NOT NULL UNIQUE, payload_hash CHAR(64) NOT NULL, result_json LONGTEXT NOT NULL',
    'intelligencecache' => 'id CHAR(64) PRIMARY KEY, payload_json LONGTEXT NOT NULL, fetched_at DATETIME NOT NULL, checked_at DATETIME NOT NULL, last_error TEXT NULL',
];
foreach ($tables as $table => $columns) {
    R::exec("CREATE TABLE IF NOT EXISTS `{$table}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
addColumn('product', 'barcode', 'VARCHAR(100) NULL');
addColumn('product', 'category_id', 'INT UNSIGNED NULL');
addColumn('stock', 'version', 'INT NOT NULL DEFAULT 1');
addColumn('saleshistory', 'stockout', 'TINYINT NOT NULL DEFAULT 0');
addColumn('saleshistory', 'one_time_quantity', 'INT NOT NULL DEFAULT 0');
addColumn('purchaseorder', 'recommendation_id', 'INT UNSIGNED NULL');
addColumn('purchaseorder', 'batch_id', 'VARCHAR(80) NULL');
addColumn('purchaseorder', 'approved_by', 'INT UNSIGNED NULL');
addColumn('purchaseorder', 'approved_at', 'DATETIME NULL');
addColumn('purchaseorder', 'received_qty', 'INT NOT NULL DEFAULT 0');
addColumn('purchaseorder', 'unit_price', 'DOUBLE NULL');
addColumn('purchaseorder', 'ai_source', 'VARCHAR(30) NULL');
addColumn('purchaseorder', 'ai_reason', 'VARCHAR(100) NULL');
addColumn('purchaseorder', 'calculation_hash', 'VARCHAR(64) NULL');
foreach (['ai_text' => 'TEXT NULL', 'ai_source' => 'VARCHAR(30) NULL', 'ai_reason' => 'VARCHAR(40) NULL', 'ai_model' => 'VARCHAR(100) NULL', 'ai_created_at' => 'DATETIME NULL'] as $column => $definition) { addColumn('forecast', $column, $definition); }
// Legacy RedBean inferred narrow columns from seed values; REST writes use a frozen schema.
foreach (['purchaseorder' => ['ai_explanation' => 'TEXT NULL'], 'product' => ['unit_cost' => 'DOUBLE NOT NULL DEFAULT 0'], 'supplierproduct' => ['unit_price' => 'DOUBLE NOT NULL DEFAULT 0']] as $table => $columns) {
    $schema = R::inspect($table);
    foreach ($columns as $column => $definition) {
        $expected = strtolower(strtok($definition, ' '));
        if (strtolower($schema[$column]) !== $expected) { R::exec("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition}"); }
    }
}
foreach (R::getAll('SELECT id, category, barcode FROM product') as $product) {
    // category is legacy text, category_id is a relation: avoid ORM name collisions.
    $name = trim((string)$product['category']) ?: 'Без категории';
    R::exec('INSERT IGNORE INTO category (name) VALUES (?)', [$name]);
    $categoryId = R::getCell('SELECT id FROM category WHERE name=?', [$name]);
    $barcode = $product['barcode'] ?: '200000' . str_pad((string)$product['id'], 6, '0', STR_PAD_LEFT);
    R::exec('UPDATE product SET category_id=?, barcode=? WHERE id=?', [$categoryId, $barcode, $product['id']]);
}
foreach ([['manager@supplymind.local', 'Менеджер закупок', 'manager'], ['warehouse@supplymind.local', 'Сотрудник склада', 'warehouse']] as [$email, $name, $role]) {
    if (!R::findOne('useraccount', 'email = ?', [$email])) {
        $user = R::dispense('useraccount');
        $user->email = $email; $user->name = $name; $user->role = $role;
        $user->password_hash = password_hash('SupplyMind2026!', PASSWORD_DEFAULT);
        R::store($user);
    }
}
// Переносим подтверждённые заказы в учёт ожидаемых поставок, не меняя остатки.
foreach (R::findAll('purchaseorder', 'status = ?', ['approved']) as $order) {
    if (!R::findOne('intransit', 'order_id = ?', [$order->id])) {
        $product = R::load('product', $order->product_id);
        R::exec('INSERT INTO intransit (order_id, product_id, warehouse_id, quantity, expected_at) VALUES (?, ?, ?, ?, ?)', [$order->id, $order->product_id, $order->warehouse_id, $order->suggested_qty, date('Y-m-d', strtotime('+' . (int)$product->lead_time_days . ' days'))]);
    }
}
echo "SupplyMind schema ready. Existing data preserved.\n";
