<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Direct OpenAI access from PHP is disabled. Use php tools/check-intelligence.php.\n");
exit(1);
