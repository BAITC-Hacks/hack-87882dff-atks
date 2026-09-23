<?php
declare(strict_types=1);

use SmartStock\ApiException;
use SmartStock\IntelligenceApiService;

// Included only after the manager authorization guard in v1.php.
$intelligence = new IntelligenceApiService();
$queryValue = static function (string $key, ?string $default = null): ?string {
    $value = $_GET[$key] ?? $default;
    if ($value !== null && !is_string($value)) { throw new ApiException('Некорректный параметр запроса.', 422); }
    return $value;
};
if ($method === 'GET') {
    $data = match ($route) {
        'intelligence/health' => $intelligence->health(),
        'intelligence/schema' => $intelligence->schema(),
        'intelligence/forecast' => $intelligence->getForecast($queryValue('sku', '')),
        'intelligence/recommendation' => $intelligence->getRecommendation($queryValue('sku', '')),
        'intelligence/recommendations' => $intelligence->getRecommendations($queryValue('status')),
        default => throw new ApiException('Маршрут Intelligence API не найден.', 404),
    };
} elseif ($method === 'POST' && $route === 'intelligence/agent/run') {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) { throw new ApiException('Запрос агента слишком большой.', 413); }
    try { $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new ApiException('Некорректный JSON запроса агента.', 400); }
    if (!$body instanceof stdClass) { throw new ApiException('Тело запроса агента должно быть JSON-объектом из Swagger.', 422); }
    if (array_keys(get_object_vars($body)) !== ['message'] || !is_string($body->message) || trim($body->message) === '' || preg_match_all('/./us', $body->message) > 4000) {
        throw new ApiException('Укажите сообщение для агента длиной от 1 до 4000 символов.', 422);
    }
    $health = $intelligence->health();
    if (!($health instanceof stdClass) || ($health->agent_available ?? false) !== true) {
        throw new ApiException('AI-агент не настроен на Python-сервере. Прогнозы и рекомендации доступны.', 503);
    }
    $data = $intelligence->runAgent($body);
} else {
    throw new ApiException('Маршрут или метод Intelligence API не поддерживается.', 404);
}
jsonResponse(['success' => true, 'source' => 'intelligence', 'data' => $data]);
