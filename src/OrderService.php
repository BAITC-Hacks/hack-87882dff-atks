<?php

declare(strict_types=1);

namespace SmartStock;

use RedBeanPHP\R;
use RuntimeException;

class OrderService
{
    public function history(int $productId, int $warehouseId): array
    {
        $rows = R::getAll('SELECT date, quantity_sold FROM saleshistory WHERE product_id = ? AND warehouse_id = ? AND date BETWEEN ? AND ? ORDER BY date', [$productId, $warehouseId, date('Y-m-d', strtotime('-29 days')), date('Y-m-d')]);
        return array_map(static fn($row) => ['date' => $row['date'], 'quantity_sold' => (int) $row['quantity_sold']], $rows);
    }

    public function draft(int $productId, int $warehouseId, array $calculation): object
    {
        // Блокировка пары товар/склад защищает от повторных черновиков с двух телефонов.
        $lock = "smartstock:{$productId}:{$warehouseId}";
        if ((int) R::getCell('SELECT GET_LOCK(?, 5)', [$lock]) !== 1) {
            throw new RuntimeException('Заказ обновляется. Повторите запрос.');
        }
        try {
            $order = R::findOne('purchaseorder', 'product_id = ? AND warehouse_id = ? AND status IN (?, ?) ORDER BY (status = ?) DESC, id DESC', [$productId, $warehouseId, 'draft', 'approved', 'approved']);
            if ($order && $order->status === 'approved') {
                return $order;
            }
            $link = R::findOne('supplierproduct', 'product_id = ? ORDER BY unit_price ASC', [$productId]);
            if (!$link) {
                throw new RuntimeException('Для товара не найден поставщик');
            }
            $order = $order ?: R::dispense('purchaseorder');
            $quantity = max((int) $link->min_order_qty, (int) $calculation['suggested_qty']);
            $fingerprint = hash('sha256', json_encode([$calculation, $quantity, (float) $link->unit_price]));
            if ($order->calculation_hash !== $fingerprint || trim((string) $order->ai_explanation) === '') {
                $order->ai_explanation = (new AiExplainer())->calculatedExplanation([...$calculation, 'suggested_qty' => $quantity]);
                $order->ai_source = 'calculation';
                $order->ai_reason = null;
                $order->calculation_hash = $fingerprint;
            }
            $order->supplier_id = $link->supplier_id;
            $order->product_id = $productId;
            $order->warehouse_id = $warehouseId;
            $order->suggested_qty = $quantity;
            $order->unit_price = (float) $link->unit_price;
            $order->status = 'draft';
            $order->created_at = $order->created_at ?: date('Y-m-d H:i:s');
            R::store($order);
            return $order;
        } finally {
            R::getCell('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function details(object $order): array
    {
        $product = R::load('product', (int) $order->product_id);
        $warehouse = R::load('warehouse', (int) $order->warehouse_id);
        $supplier = R::load('supplier', (int) $order->supplier_id);
        $stock = R::findOne('stock', 'product_id = ? AND warehouse_id = ?', [(int) $product->id, (int) $warehouse->id]);
        try {
            $calculation = (new ReorderCalculator())->calculateReorderPoint((int) $product->id, (int) $warehouse->id);
        } catch (RuntimeException $e) {
            $calculation = null;
        }
        $data = \beanToArray($order);
        $price = $order->unit_price;
        if ($price === null) {
            $price = R::getCell('SELECT unit_price FROM supplierproduct WHERE supplier_id = ? AND product_id = ?', [$supplier->id, $product->id]);
        }
        $data['unit_price'] = (float) $price;
        $data['total_amount'] = round((float) $price * (int) $order->suggested_qty, 2);
        $data['ai_source'] = $order->ai_source ?: 'calculation';
        return ['product' => \beanToArray($product), 'warehouse' => \beanToArray($warehouse), 'supplier' => $supplier->id ? \beanToArray($supplier) : null, 'stock' => $stock ? \beanToArray($stock) : null, 'calculation' => $calculation, 'purchase_order' => $data, 'ai_explanation' => (string) $order->ai_explanation];
    }
}
