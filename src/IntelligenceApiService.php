<?php
declare(strict_types=1);

namespace SmartStock;

class IntelligenceApiService
{
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim(trim($baseUrl ?? (string)getenv('INTELLIGENCE_API_URL')), '/');
        if ($this->baseUrl !== '') {
            $url = parse_url($this->baseUrl);
            if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
                || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
                || preg_match('/[\x00-\x20\x7f]/', $this->baseUrl)) {
                throw new ApiException('Некорректный INTELLIGENCE_API_URL на PHP-сервере.', 503);
            }
        }
    }

    public function health(): array|\stdClass { return $this->request('GET', '/health'); }
    public function configured(): bool { return $this->baseUrl !== ''; }
    public function cacheKey(): string { return hash('sha256', $this->baseUrl); }
    public function schema(): array|\stdClass { return $this->request('GET', '/openapi.json'); }
    public function getForecast(string $sku): array|\stdClass { return $this->request('GET', '/forecast/' . $this->sku($sku)); }
    public function getRecommendation(string $sku): array|\stdClass { return $this->request('GET', '/recommendation/' . $this->sku($sku)); }

    public function getRecommendations(?string $status = null): array|\stdClass
    {
        if ($status !== null && !preg_match('/^[a-z_]{1,60}$/', $status)) {
            throw new ApiException('Некорректный статус рекомендации.', 422);
        }
        $query = $status === null ? '' : '?' . http_build_query(['status' => $status], '', '&', PHP_QUERY_RFC3986);
        return $this->request('GET', '/recommendations' . $query);
    }

    public function runAgent(\stdClass $payload): \stdClass
    {
        // The request object comes from the actual Swagger contract, not an assumed message/query field.
        $result = $this->request('POST', '/agent/run', $payload);
        if (!$result instanceof \stdClass || !is_string($result->answer ?? null)
            || !is_array($result->tools_used ?? null) || !property_exists($result, 'data')
            || !is_bool($result->requires_human_review ?? null)) {
            throw new ApiException('Intelligence Service вернул некорректный ответ агента.', 502);
        }
        return $result;
    }

    private function sku(string $sku): string
    {
        if ($sku === '' || trim($sku) !== $sku || strlen($sku) > 190 || in_array($sku, ['.', '..'], true)
            || preg_match('/[\x00-\x1f\x7f]/', $sku)) {
            throw new ApiException('Укажите корректный артикул.', 422);
        }
        return rawurlencode($sku);
    }

    private function request(string $method, string $path, ?\stdClass $payload = null): array|\stdClass
    {
        if ($this->baseUrl === '') {
            throw new ApiException('Intelligence Service не подключён: задайте INTELLIGENCE_API_URL на PHP-сервере.', 503);
        }
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if ($body !== null && strlen($body) > 65536) {
            throw new ApiException('Запрос агента слишком большой.', 413);
        }
        $result = $this->send($method, $this->baseUrl . $path, $body);
        if (($result['too_large'] ?? false) || strlen($result['body']) > self::MAX_RESPONSE_BYTES) {
            throw new ApiException('Ответ Intelligence Service превышает допустимый размер.', 502);
        }
        if ($result['errno'] === CURLE_OPERATION_TIMEDOUT) {
            throw new ApiException('Intelligence Service не ответил вовремя. Складские операции доступны.', 504);
        }
        if ($result['errno'] !== 0) {
            throw new ApiException('Intelligence Service временно недоступен. Складские операции доступны.', 503);
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            [$status, $message] = match ($result['status']) {
                404 => [404, 'Intelligence Service не нашёл ресурс. Проверьте артикул и контракт API.'],
                422 => [422, 'Intelligence Service отклонил параметры. Проверьте запрос по Swagger.'],
                429 => [429, 'Лимит запросов Intelligence Service. Повторите позже.'],
                401, 403 => [502, 'Intelligence Service отклонил доступ PHP-сервера.'],
                default => [503, 'Intelligence Service временно недоступен. Складские операции доступны.'],
            };
            // Never forward raw upstream errors: they can contain credentials or internal tracebacks.
            throw new ApiException($message, $status);
        }
        try { $decoded = json_decode($result['body'], false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new ApiException('Intelligence Service вернул некорректный JSON.', 502); }
        if (!$decoded instanceof \stdClass && !is_array($decoded)) {
            throw new ApiException('Intelligence Service вернул некорректную структуру ответа.', 502);
        }
        return $decoded;
    }

    protected function send(string $method, string $url, ?string $body): array
    {
        if (!function_exists('curl_init')) { throw new ApiException('На PHP-сервере недоступен cURL.', 503); }
        $response = ''; $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $method === 'POST' ? 25 : (str_ends_with($url, '/health') ? 3 : 15),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { $tooLarge = true; return 0; }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch);
        curl_close($ch);
        return ['status' => $status, 'errno' => $errno, 'body' => $response, 'too_large' => $tooLarge];
    }
}
