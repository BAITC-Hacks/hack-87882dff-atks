<?php

declare(strict_types=1);

use RedBeanPHP\R;

require_once __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('Asia/Almaty');

loadEnv(__DIR__ . '/.env');

if (!defined('SMARTSTOCK_CONFIG_LOADED')) {
    define('SMARTSTOCK_CONFIG_LOADED', true);
}

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function dbConfig(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function connectDb(): void
{
    if (R::testConnection()) {
        return;
    }

    $host = dbConfig('DB_HOST', 'localhost');
    $dbname = dbConfig('DB_NAME', 'smartstock');
    $user = dbConfig('DB_USER', 'root');
    $password = dbConfig('DB_PASSWORD', '');

    R::setup("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $password);
    R::freeze(false);
    R::useFeatureSet('novice/latest');
}

function applyCorsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
}

function handlePreflight(): void
{
    applyCorsHeaders();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    applyCorsHeaders();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function errorResponse(string $message, int $statusCode = 500, array $details = []): void
{
    jsonResponse([
        'success' => false,
        'error' => $message,
        'details' => $details,
    ], $statusCode);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        errorResponse('Некорректный JSON в теле запроса', 400);
    }

    return $data;
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        errorResponse('Метод не поддерживается', 405, ['expected' => $method]);
    }
}

function beanToArray(object $bean): array
{
    $array = $bean->export();
    foreach ($array as $key => $value) {
        if (!in_array($key, ['sku', 'barcode'], true) && is_numeric($value)) {
            $array[$key] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }
    }
    return $array;
}
