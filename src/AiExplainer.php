<?php

declare(strict_types=1);

namespace SmartStock;

class AiExplainer
{
    public function explain(object $product, array $calculation, array $history): string
    {
        return $this->analyze($product, $calculation, $history)['text'];
    }

    public function analyze(object $product, array $calculation, array $history): array
    {
        return $this->localResult($this->calculatedExplanation($calculation));
    }

    public function analyzeForecast(string $productName, array $analysis, string $forecastSource): array
    {
        return $this->localResult($analysis['explanation']);
    }

    private function localResult(string $text): array
    {
        // Compatibility only: new LLM requests must go through the Python service.
        return ['text' => $text, 'source' => 'calculation', 'reason' => 'intelligence_service_required', 'model' => null];
    }

    public static function reasonMessage(?string $reason): string
    {
        return 'Новые объяснения AI доступны только через Python Intelligence Service. Сохранено исходное объяснение расчёта.';
    }

    public function calculatedExplanation(array $c): string
    {
        $days = $c['average_daily_sales'] > 0 ? round($c['current_stock'] / $c['average_daily_sales'], 1) : null;
        $coverage = $days === null ? 'Продаж за период нет.' : sprintf('Запаса хватит примерно на %s дн., срок поставки — %d дн.', $days, $c['lead_time_days']);
        $change = (float) $c['demand_change_percent'];
        $trend = abs($change) >= 20 ? sprintf('Спрос за неделю %s на %s%%.', $change > 0 ? 'вырос' : 'снизился', abs($change)) : 'Резкого изменения спроса за неделю нет.';
        return sprintf('%s Заказ %d шт. учитывает остаток, резерв и продажи на следующие 7 дней. %s', $coverage, $c['suggested_qty'], $trend);
    }
}
