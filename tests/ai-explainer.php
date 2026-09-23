<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/FakeAiExplainer.php';

$checks = 0;
function checkAi(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: {$message}\n"; $checks++;
}
$originalKey = getenv('OPENAI_API_KEY');
$snapshot = ['sku' => 'TEST', 'recommended_quantity' => 42, 'explanation' => 'Расчётный текст.'];
try {
    foreach (['', 'test-key-never-sent'] as $key) {
        putenv('OPENAI_API_KEY=' . $key);
        $fake = new FakeAiExplainer();
        $result = $fake->analyzeForecast('Test', $snapshot, 'mock');
        checkAi($result['source'] === 'calculation' && $result['reason'] === 'intelligence_service_required', 'Legacy endpoint explicitly requires Python for new AI replies');
        checkAi($fake->calls === 0 && $fake->payload === [], 'No direct OpenAI transport, even with a configured legacy key');
        checkAi($result['text'] === $snapshot['explanation'] && $result['model'] === null, 'Stored calculation retained without invented model');
        checkAi(!str_contains(json_encode($result), 'test-key-never-sent'), 'Legacy key never appears in response');
    }
    $code = file_get_contents(__DIR__ . '/../src/AiExplainer.php');
    checkAi(!str_contains($code, 'curl_') && !str_contains($code, 'OPENAI_API_KEY') && !str_contains($code, 'api.openai.com'), 'Compatibility explainer has no OpenAI transport or credential access');
    echo "\n{$checks} compatibility checks passed without network calls.\n";
} finally { putenv($originalKey === false ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $originalKey); }
