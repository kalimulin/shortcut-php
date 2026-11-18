<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/src/bootstrap_api.php',
    dirname(__DIR__) . '/src/bootstrap_api.php',
    dirname(__DIR__) . '/backend/src/bootstrap_api.php',
    __DIR__ . '/backend/src/bootstrap_api.php',
];

foreach ($candidates as $bootstrap) {
    if (is_file($bootstrap)) {
        require $bootstrap;
        return;
    }
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'error' => [
        'code' => 'BOOTSTRAP_NOT_FOUND',
        'message' => 'Не найден файл bootstrap_api.php. Проверьте структуру каталогов.',
    ],
], JSON_UNESCAPED_UNICODE);
