<?php

declare(strict_types=1);

use RedBeanPHP\R;

require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    connectDb();

    if (in_array('useraccount', R::inspect(), true)) {
        throw new RuntimeException('База SupplyMind уже настроена. Используйте setup.php и demo-demand.php: seed.php удаляет старые данные и запрещён после миграции.');
    }

    // Очищаем демо-данные, чтобы seed можно было запускать повторно.
    R::exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['purchaseorder', 'supplierproduct', 'saleshistory', 'stock', 'supplier', 'warehouse', 'product'] as $table) {
        R::wipe($table);
    }
    R::exec('SET FOREIGN_KEY_CHECKS=1');

    $products = [
        ['sku' => 'SKU-RICE-01', 'name' => 'Рис жасмин 5 кг', 'category' => 'Бакалея', 'unit_cost' => 3900, 'lead_time_days' => 4, 'base_sales' => 9],
        ['sku' => 'SKU-OIL-02', 'name' => 'Масло подсолнечное 1 л', 'category' => 'Бакалея', 'unit_cost' => 780, 'lead_time_days' => 5, 'base_sales' => 16],
        ['sku' => 'SKU-MILK-03', 'name' => 'Молоко 2.5% 1 л', 'category' => 'Молочные продукты', 'unit_cost' => 410, 'lead_time_days' => 2, 'base_sales' => 24],
        ['sku' => 'SKU-COFFEE-04', 'name' => 'Кофе молотый 250 г', 'category' => 'Напитки', 'unit_cost' => 1850, 'lead_time_days' => 7, 'base_sales' => 6],
        ['sku' => 'SKU-DIAPER-05', 'name' => 'Подгузники размер 4', 'category' => 'Детские товары', 'unit_cost' => 6200, 'lead_time_days' => 6, 'base_sales' => 5],
    ];

    $productBeans = [];
    foreach ($products as $item) {
        $product = R::dispense('product');
        $product->sku = $item['sku'];
        $product->name = $item['name'];
        $product->category = $item['category'];
        $product->unit_cost = $item['unit_cost'];
        $product->lead_time_days = $item['lead_time_days'];
        $product->base_sales = $item['base_sales'];
        R::store($product);
        $productBeans[] = $product;
    }

    $warehouses = [
        ['name' => 'Центральный склад', 'location' => 'Алматы'],
        ['name' => 'Склад северного района', 'location' => 'Астана'],
    ];

    $warehouseBeans = [];
    foreach ($warehouses as $item) {
        $warehouse = R::dispense('warehouse');
        $warehouse->name = $item['name'];
        $warehouse->location = $item['location'];
        R::store($warehouse);
        $warehouseBeans[] = $warehouse;
    }

    $stockPlan = [
        ['product_index' => 0, 'warehouse_index' => 0, 'qty' => 38, 'safety_stock' => 10, 'reorder_point' => 48],
        ['product_index' => 1, 'warehouse_index' => 0, 'qty' => 120, 'safety_stock' => 18, 'reorder_point' => 92],
        ['product_index' => 2, 'warehouse_index' => 0, 'qty' => 31, 'safety_stock' => 20, 'reorder_point' => 65],
        ['product_index' => 3, 'warehouse_index' => 1, 'qty' => 74, 'safety_stock' => 12, 'reorder_point' => 55],
        ['product_index' => 4, 'warehouse_index' => 1, 'qty' => 14, 'safety_stock' => 8, 'reorder_point' => 35],
    ];

    foreach ($stockPlan as $item) {
        $stock = R::dispense('stock');
        $stock->product_id = $productBeans[$item['product_index']]->id;
        $stock->warehouse_id = $warehouseBeans[$item['warehouse_index']]->id;
        $stock->quantity_on_hand = $item['qty'];
        $stock->safety_stock = $item['safety_stock'];
        $stock->reorder_point = $item['reorder_point'];
        R::store($stock);
    }

    $suppliers = [
        ['name' => 'KazFood Distribution', 'contact_info' => '+7 701 100 10 10, sales@kazfood.kz', 'avg_lead_time_days' => 4],
        ['name' => 'FreshLine Market Supply', 'contact_info' => '+7 702 200 20 20, order@freshline.kz', 'avg_lead_time_days' => 3],
        ['name' => 'Global Home Goods', 'contact_info' => '+7 705 300 30 30, b2b@ghg.kz', 'avg_lead_time_days' => 7],
    ];

    $supplierBeans = [];
    foreach ($suppliers as $item) {
        $supplier = R::dispense('supplier');
        $supplier->name = $item['name'];
        $supplier->contact_info = $item['contact_info'];
        $supplier->avg_lead_time_days = $item['avg_lead_time_days'];
        R::store($supplier);
        $supplierBeans[] = $supplier;
    }

    $supplierProducts = [
        [0, 0, 3700, 20], [0, 1, 735, 30], [1, 2, 390, 40],
        [0, 3, 1760, 12], [2, 4, 5900, 10], [1, 1, 760, 25],
    ];

    foreach ($supplierProducts as [$supplierIndex, $productIndex, $price, $minQty]) {
        $link = R::dispense('supplierproduct');
        $link->supplier_id = $supplierBeans[$supplierIndex]->id;
        $link->product_id = $productBeans[$productIndex]->id;
        $link->unit_price = $price;
        $link->min_order_qty = $minQty;
        R::store($link);
    }

    $today = new DateTimeImmutable('today');
    foreach ($stockPlan as $item) {
        $product = $productBeans[$item['product_index']];
        $warehouse = $warehouseBeans[$item['warehouse_index']];

        for ($day = 29; $day >= 0; $day--) {
            $date = $today->modify("-{$day} days");
            $weekdayBoost = in_array((int) $date->format('N'), [5, 6, 7], true) ? 1.25 : 1.0;
            $noise = random_int(-2, 3);
            $base = (int) $product->base_sales;

            // Для молока создаём резкий рост за последнюю неделю, чтобы AI мог объяснить аномалию.
            $anomalyBoost = ((string) $product->sku === 'SKU-MILK-03' && $day <= 6) ? 2.15 : 1.0;
            $sold = max(0, (int) round($base * $weekdayBoost * $anomalyBoost + $noise));

            $history = R::dispense('saleshistory');
            $history->product_id = $product->id;
            $history->warehouse_id = $warehouse->id;
            $history->date = $date->format('Y-m-d');
            $history->quantity_sold = $sold;
            R::store($history);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Демо-данные SmartStock созданы',
        'products' => count($productBeans),
        'warehouses' => count($warehouseBeans),
        'suppliers' => count($supplierBeans),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка seed.php: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
