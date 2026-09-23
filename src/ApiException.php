<?php
declare(strict_types=1);
namespace SmartStock;
class ApiException extends \RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
