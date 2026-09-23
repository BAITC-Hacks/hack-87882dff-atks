<?php
declare(strict_types=1);

class FakeAiExplainer extends SmartStock\AiExplainer {
    public int $calls = 0;
    public array $payload = [];
    public array $response;
    public function __construct() {
        $this->response = ['status' => 200, 'errno' => 0, 'body' => json_encode([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Объяснение для теста. Заказ не подтверждён.']]]],
        ], JSON_UNESCAPED_UNICODE)];
    }
    protected function send(array $payload, string $key): array {
        $this->calls++; $this->payload = $payload;
        return $this->response;
    }
}
