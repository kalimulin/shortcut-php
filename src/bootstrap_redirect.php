<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Config;
use App\Controllers\RedirectController;
use App\Database;
use App\Repositories\BlacklistRepository;
use App\Repositories\ClickRepository;
use App\Repositories\ShortcutRepository;
use App\Services\UrlCheckService;
use App\Services\UrlTools;

$config = new Config();

try {
    $db = new Database($config);
    $shortcutRepo = new ShortcutRepository($db);
    $clickRepo = new ClickRepository($db);
    $blacklistRepo = new BlacklistRepository($db);
    $urlTools = new UrlTools($config);
    $urlCheckService = new UrlCheckService($config, $urlTools, $blacklistRepo);

    $code = '';
    if (isset($_GET['code']) && is_string($_GET['code'])) {
        $code = $_GET['code'];
    } else {
        $uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        if (preg_match('#^/s/([0-9A-Za-z_-]{1,64})/?$#', $uriPath, $m) === 1) {
            $code = $m[1];
        }
    }

    $controller = new RedirectController($config, $shortcutRepo, $clickRepo, $urlCheckService);
    $controller->handle($code);
} catch (Throwable) {
    $fallback = rtrim($config->appUrl(), '/') . '/links/errors/not-found';
    http_response_code(303);
    header('Location: ' . $fallback);
    exit;
}
