<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/src/bootstrap_redirect.php',
    dirname(__DIR__) . '/src/bootstrap_redirect.php',
    dirname(__DIR__) . '/backend/src/bootstrap_redirect.php',
    __DIR__ . '/backend/src/bootstrap_redirect.php',
];

foreach ($candidates as $bootstrap) {
    if (is_file($bootstrap)) {
        require $bootstrap;
        return;
    }
}

http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo 'Bootstrap file not found.';
