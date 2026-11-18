<?php

declare(strict_types=1);

// Роутер для встроенного PHP-сервера локальной разработки:
//   php -S 127.0.0.1:8000 router_dev.php
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($path === '/s' || $path === '/s/' || str_starts_with($path, '/s/')) {
    if (preg_match('#^/s/([0-9A-Za-z_-]{1,64})/?$#', $path, $m) === 1) {
        $_GET['code'] = $m[1];
    } else {
        $_GET['code'] = '';
    }
    require __DIR__ . '/public/redirect.php';
    return true;
}

if ($path === '/api' || $path === '/api/' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/api.php';
    return true;
}

$staticFile = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($staticFile)) {
    return false;
}

$spaIndex = __DIR__ . '/public/index.html';
if (is_file($spaIndex)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($spaIndex);
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Use /api/* or /s/{code}']], JSON_UNESCAPED_UNICODE);
return true;
