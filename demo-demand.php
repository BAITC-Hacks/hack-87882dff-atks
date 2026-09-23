<?php
declare(strict_types=1);
use RedBeanPHP\R;
require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') { exit; }
connectDb();
if (R::findOne('product', 'sku=?', ['SM-DEMO-OAT-06'])) { echo "Demand demo already exists.\n"; exit; }
$warehouse = R::findOne('warehouse', 'ORDER BY id');
$supplier = R::findOne('supplier', 'ORDER BY id');
if (!$warehouse) {
    foreach ([['Центральный склад', 'Алматы'], ['Склад северного района', 'Астана']] as [$name, $location]) {
        $newWarehouse = R::dispense('warehouse'); $newWarehouse->name = $name; $newWarehouse->location = $location; R::store($newWarehouse);
        $warehouse ??= $newWarehouse;
    }
}
if (!$supplier) {
    $supplier = R::dispense('supplier'); $supplier->name = 'Демо-поставщик'; $supplier->contact_info = 'demo@example.invalid'; $supplier->avg_lead_time_days = 4; R::store($supplier);
}
R::exec('INSERT IGNORE INTO category (name) VALUES (?)', ['Бакалея']);
$product = R::dispense('product');
$product->sku = 'SM-DEMO-OAT-06'; $product->name = 'Овсяные хлопья 500 г'; $product->category = 'Бакалея';
$product->barcode = '200000000006'; $product->unit_cost = 650; $product->lead_time_days = 4;
$product->category_id = R::getCell('SELECT id FROM category WHERE name=?', ['Бакалея']);
R::store($product);
$stock = R::dispense('stock'); $stock->product_id = $product->id; $stock->warehouse_id = $warehouse->id;
$stock->quantity_on_hand = 12; $stock->safety_stock = 15; $stock->reorder_point = 70; $stock->version = 1; R::store($stock);
$link = R::dispense('supplierproduct'); $link->supplier_id = $supplier->id; $link->product_id = $product->id; $link->unit_price = 600; $link->min_order_qty = 24; R::store($link);
for ($day = 29; $day >= 0; $day--) {
    $sale = R::dispense('saleshistory'); $sale->product_id = $product->id; $sale->warehouse_id = $warehouse->id;
    $sale->date = date('Y-m-d', strtotime("-{$day} days")); $sale->stockout = in_array($day, [10, 9], true) ? 1 : 0;
    $sale->one_time_quantity = $day === 15 ? 180 : 0;
    $sale->quantity_sold = $sale->stockout ? 0 : ($day < 7 ? 19 : 13) + ($day % 3) + $sale->one_time_quantity;
    R::store($sale);
}
echo "Added one clearly identified demo SKU with stockout and one-time sales history.\n";
