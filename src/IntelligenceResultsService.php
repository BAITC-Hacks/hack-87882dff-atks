<?php
declare(strict_types=1);
namespace SmartStock;
use RedBeanPHP\R;

class IntelligenceResultsService
{
    private IntelligenceApiService $client;
    public function __construct(?IntelligenceApiService $client = null) { $this->client = $client ?? new IntelligenceApiService(); }

    public function get(bool $refresh = false): array
    {
        if (!$this->client->configured()) { throw new ApiException('Python Intelligence Service не подключён.', 503); }
        $key = $this->client->cacheKey();
        $cached = R::getRow('SELECT * FROM intelligencecache WHERE id=?', [$key]);
        if (!$refresh && $cached && time() - strtotime($cached['checked_at'] . ' UTC') < 60) { return $this->envelope($cached, true); }
        $lock = 'intelligence:' . substr($key, 0, 40);
        if ((int)R::getCell('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            if ($cached) { return $this->envelope($cached, true) + ['refreshing' => true]; }
            throw new ApiException('Результаты Python загружаются. Повторите запрос.', 409);
        }
        try {
            $list = $this->client->getRecommendations();
            $this->validate($list);
            $health = null; $healthError = null;
            try {
                $health = $this->client->health();
                if (!$health instanceof \stdClass || !is_bool($health->agent_available ?? null)
                    || !in_array($health->status ?? null, ['ok', 'degraded'], true)) {
                    throw new ApiException('Не удалось проверить состояние Python-сервиса.', 502);
                }
            } catch (ApiException $e) { $health = null; $healthError = $e->getMessage(); }
            $payload = json_encode(['results' => $list, 'health' => $health, 'health_error' => $healthError], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            // Keep cache timestamps in UTC independently of the MySQL/PHP host timezones.
            $now = gmdate('Y-m-d H:i:s');
            R::exec('INSERT INTO intelligencecache (id,payload_json,fetched_at,checked_at,last_error) VALUES (?,?,?,?,NULL) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),fetched_at=VALUES(fetched_at),checked_at=VALUES(checked_at),last_error=NULL', [$key, $payload, $now, $now]);
            return $this->envelope(R::getRow('SELECT * FROM intelligencecache WHERE id=?', [$key]), false);
        } catch (ApiException $e) {
            if (!$cached) { throw $e; }
            R::exec('UPDATE intelligencecache SET checked_at=?,last_error=? WHERE id=?', [gmdate('Y-m-d H:i:s'), $e->getMessage(), $key]);
            $cached['last_error'] = $e->getMessage();
            return $this->envelope($cached, true);
        } finally { R::getCell('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    public function validate(array|\stdClass $list): void
    {
        if (!$list instanceof \stdClass || !is_int($list->total ?? null) || !is_array($list->items ?? null)
            || $list->total !== count($list->items) || $list->total > 10000) {
            throw new ApiException('Python вернул неполный или некорректный список рекомендаций.', 502);
        }
        $seen = [];
        if (isset($list->catalog)) {
            if (!is_array($list->catalog) || count($list->catalog) > 10000) { throw new ApiException('Некорректный каталог товаров.', 502); }
            $catalogSkus = [];
            foreach ($list->catalog as $product) {
                if (!$product instanceof \stdClass || !is_string($product->sku ?? null) || $product->sku === '' || strlen($product->sku) > 190
                    || isset($catalogSkus[$product->sku]) || !is_bool($product->has_forecast ?? null)) { throw new ApiException('Некорректная позиция каталога.', 502); }
                foreach (['product_name', 'article', 'unit'] as $field) {
                    if (isset($product->$field) && !is_string($product->$field)) { throw new ApiException('Некорректное описание товара.', 502); }
                }
                $catalogSkus[$product->sku] = true;
            }
        }
        foreach ($list->items as $item) {
            if (!$item instanceof \stdClass || !is_string($item->sku ?? null) || $item->sku === '' || strlen($item->sku) > 190
                || isset($seen[$item->sku]) || !is_string($item->forecast_date ?? null) || !is_string($item->model_name ?? null)
                || !is_string($item->urgency ?? null) || !(($item->explanation_components ?? null) instanceof \stdClass)) {
                throw new ApiException('Python вернул некорректную или повторную позицию.', 502);
            }
            $seen[$item->sku] = true;
            $components = $item->explanation_components;
            if ((isset($components->formula) && !is_string($components->formula))
                || (isset($components->issues) && (!is_array($components->issues) || array_filter($components->issues, static fn($issue) => !is_string($issue))))) {
                throw new ApiException('Python вернул некорректное объяснение.', 502);
            }
            foreach (['stock_date', 'effective_model_name', 'demand_basis'] as $field) {
                if (isset($item->$field) && !is_string($item->$field)) { throw new ApiException('Python вернул некорректное поле: ' . $field, 502); }
            }
            foreach (['bulk_quantity_excluded', 'rolling_mean_3', 'historical_lost_demand', 'seasonal_reference_demand', 'annual_growth_factor'] as $field) {
                if (isset($item->$field) && ((!is_int($item->$field) && !is_float($item->$field)) || !is_finite((float)$item->$field))) {
                    throw new ApiException('Python вернул некорректное число: ' . $field, 502);
                }
            }
            foreach (['forecast_demand', 'current_stock', 'in_transit', 'available_stock', 'net_requirement', 'order_multiple', 'recommended_quantity'] as $field) {
                if (!property_exists($item, $field) || ($item->$field === null && $field === 'forecast_demand')
                    || ($item->$field !== null && ((!is_int($item->$field) && !is_float($item->$field)) || !is_finite((float)$item->$field)))) {
                    throw new ApiException('Python вернул некорректное число: ' . $field, 502);
                }
            }
        }
    }

    private function envelope(array $row, bool $cached): array
    {
        $data = json_decode($row['payload_json'], false, 64, JSON_THROW_ON_ERROR);
        return ['source' => 'intelligence', 'results' => $data->results, 'health' => $data->health,
            'health_error' => $data->health_error, 'fetched_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['fetched_at'] . ' UTC')),
            'cached' => $cached, 'stale' => $row['last_error'] !== null || time() - strtotime($row['fetched_at'] . ' UTC') > 60,
            'error' => $row['last_error'], 'read_only' => true, 'live_inventory_sync' => false];
    }
}
