<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Config;
use App\Controllers\AuthController;
use App\Controllers\CampaignController;
use App\Controllers\FeedbackController;
use App\Controllers\InfoController;
use App\Controllers\ProfileController;
use App\Controllers\PublicController;
use App\Controllers\ShortcutController;
use App\Controllers\StatsController;
use App\Controllers\UrlCheckController;
use App\Database;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Repositories\BlacklistRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ClickRepository;
use App\Repositories\EmailVerificationRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\ShortcutRepository;
use App\Repositories\UserRepository;
use App\Services\BotProtectionService;
use App\Services\CampaignService;
use App\Services\JwtService;
use App\Services\MailerService;
use App\Services\QrSvgService;
use App\Services\ShortcutService;
use App\Services\StatsExportService;
use App\Services\StatsService;
use App\Services\UrlCheckService;
use App\Services\UrlTools;

$config = new Config();
Response::applyCors($config);

$request = Request::fromGlobals();
if ($request->method === 'OPTIONS') {
    Response::noContent();
}

try {
    $db = new Database($config);

    // Репозитории
    $userRepo = new UserRepository($db);
    $blacklistRepo = new BlacklistRepository($db);
    $rateLimitRepo = new RateLimitRepository($db);
    $campaignRepo = new CampaignRepository($db);
    $shortcutRepo = new ShortcutRepository($db);
    $clickRepo = new ClickRepository($db);
    $emailVerificationRepo = new EmailVerificationRepository($db);
    $passwordResetRepo = new PasswordResetRepository($db);

    // Сервисы
    $jwt = new JwtService($config);
    $urlTools = new UrlTools($config);
    $urlCheckService = new UrlCheckService($config, $urlTools, $blacklistRepo);
    $shortcutService = new ShortcutService($config, $urlTools, $urlCheckService, $shortcutRepo, $campaignRepo);
    $campaignService = new CampaignService($campaignRepo);
    $statsService = new StatsService($config, $clickRepo);
    $statsExportService = new StatsExportService();
    $qrSvgService = new QrSvgService();
    $mailerService = new MailerService($config);
    $botProtection = new BotProtectionService($config);

    // Middleware
    $auth = new AuthMiddleware($jwt);
    $rateLimit = new RateLimitMiddleware($rateLimitRepo);

    // Контроллеры
    $infoCtrl = new InfoController($config, $db);
    $authCtrl = new AuthController($userRepo, $jwt, $emailVerificationRepo, $mailerService, $botProtection, $config, $passwordResetRepo);
    $profileCtrl = new ProfileController($userRepo);
    $publicCtrl = new PublicController($shortcutService, $urlCheckService, $qrSvgService);
    $shortcutCtrl = new ShortcutController($shortcutService);
    $campaignCtrl = new CampaignController($campaignService, $shortcutService);
    $statsCtrl = new StatsController($statsService, $statsExportService, $shortcutService, $campaignService);
    $urlCheckCtrl = new UrlCheckController($urlCheckService);
    $feedbackCtrl = new FeedbackController($mailerService, $botProtection, $config);

    $requireAuth = [$auth->requireAuth()];
    $requireAdmin = [$auth->requireAdmin()];
    $publicShortcutRl = [$rateLimit->limit('public_shortcut', $config->getInt('RATE_LIMIT_PUBLIC_SHORTCUT_PER_MIN', 20), 60)];
    $loginRl = [$rateLimit->limit('auth_login', $config->getInt('RATE_LIMIT_LOGIN_PER_MIN', 15), 60)];
    $feedbackRl = [$rateLimit->limit('feedback', $config->getInt('RATE_LIMIT_FEEDBACK_PER_MIN', 10), 60)];

    $router = new Router();

    // Служебные
    $router->add('GET', '/info', [$infoCtrl, 'info']);

    // Авторизация и профиль
    $router->add('POST', '/auth/register', [$authCtrl, 'register'], $loginRl);
    $router->add('POST', '/auth/verify-email', [$authCtrl, 'verifyEmail']);
    $router->add('POST', '/auth/resend-verification', [$authCtrl, 'resendVerification'], $loginRl);
    $router->add('POST', '/auth/forgot-password', [$authCtrl, 'forgotPassword'], $loginRl);
    $router->add('POST', '/auth/reset-password', [$authCtrl, 'resetPassword'], $loginRl);
    $router->add('POST', '/auth/login', [$authCtrl, 'login'], $loginRl);
    $router->add('PATCH', '/auth/password', [$authCtrl, 'changePassword'], $requireAuth);
    $router->add('GET', '/profile', [$profileCtrl, 'get'], $requireAuth);
    $router->add('PATCH', '/profile', [$profileCtrl, 'update'], $requireAuth);
    $router->add('DELETE', '/profile', [$profileCtrl, 'delete'], $requireAuth);

    // Публичные ручки (гостевые ссылки, проверка URL, QR, обратная связь)
    $router->add('POST', '/public/shortcuts', [$publicCtrl, 'createShortcut'], $publicShortcutRl);
    $router->add('POST', '/public/url-check', [$publicCtrl, 'checkUrl']);
    $router->add('GET', '/public/qr/{code}', [$publicCtrl, 'getQrMeta']);
    $router->add('GET', '/public/qr/{code}/image', [$publicCtrl, 'getQrImage']);
    $router->add('POST', '/feedback', [$feedbackCtrl, 'send'], $feedbackRl);
    $router->add('POST', '/public/feedback', [$feedbackCtrl, 'send'], $feedbackRl);

    // Ссылки и теги
    $router->add('GET', '/shortcuts', [$shortcutCtrl, 'list'], $requireAuth);
    $router->add('POST', '/shortcuts', [$shortcutCtrl, 'create'], $requireAuth);
    $router->add('GET', '/shortcuts/{id}', [$shortcutCtrl, 'get'], $requireAuth);
    $router->add('PATCH', '/shortcuts/{id}', [$shortcutCtrl, 'update'], $requireAuth);
    $router->add('PATCH', '/shortcuts/{id}/status', [$shortcutCtrl, 'setStatus'], $requireAuth);
    $router->add('DELETE', '/shortcuts/{id}', [$shortcutCtrl, 'delete'], $requireAuth);
    $router->add('POST', '/shortcuts/{code}/claim', [$shortcutCtrl, 'claim'], $requireAuth);
    $router->add('GET', '/tags', [$shortcutCtrl, 'listTags'], $requireAuth);

    // Кампании
    $router->add('GET', '/campaigns', [$campaignCtrl, 'list'], $requireAuth);
    $router->add('POST', '/campaigns', [$campaignCtrl, 'create'], $requireAuth);
    $router->add('GET', '/campaigns/{id}', [$campaignCtrl, 'get'], $requireAuth);
    $router->add('PATCH', '/campaigns/{id}', [$campaignCtrl, 'update'], $requireAuth);
    $router->add('DELETE', '/campaigns/{id}', [$campaignCtrl, 'delete'], $requireAuth);
    $router->add('GET', '/campaigns/{id}/shortcuts', [$campaignCtrl, 'listShortcuts'], $requireAuth);
    $router->add('POST', '/campaigns/{id}/shortcuts', [$campaignCtrl, 'addShortcut'], $requireAuth);
    $router->add('DELETE', '/campaigns/{id}/shortcuts/{shortcutId}', [$campaignCtrl, 'removeShortcut'], $requireAuth);

    // Статистика и экспорт
    $router->add('GET', '/stats', [$statsCtrl, 'accountStats'], $requireAuth);
    $router->add('GET', '/stats/shortcuts/{id}', [$statsCtrl, 'shortcutStats'], $requireAuth);
    $router->add('GET', '/stats/campaigns/{id}', [$statsCtrl, 'campaignStats'], $requireAuth);
    $router->add('GET', '/stats/shortcuts/{id}/export', [$statsCtrl, 'exportShortcut'], $requireAuth);
    $router->add('GET', '/stats/campaigns/{id}/export', [$statsCtrl, 'exportCampaign'], $requireAuth);

    // Проверка URL и чёрный список (url-check)
    $router->add('POST', '/url-check', [$urlCheckCtrl, 'check']);
    $router->add('GET', '/blacklist', [$urlCheckCtrl, 'listBlacklist'], $requireAuth);
    $router->add('POST', '/blacklist', [$urlCheckCtrl, 'addBlacklist'], $requireAdmin);
    $router->add('DELETE', '/blacklist/{id}', [$urlCheckCtrl, 'deleteBlacklist'], $requireAdmin);

    $router->dispatch($request);
} catch (ApiException $e) {
    Response::error($e->statusCode, $e->errorCode, $e->getMessage(), $e->details);
} catch (Throwable $e) {
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/error.log';
    $logEntry = sprintf(
        "[%s] ERROR 500: %s in %s:%d\nStack trace:\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
        str_repeat('=', 60)
    );
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    @error_log('Shortcut API Error: ' . $e->getMessage());

    $debug = $config->getBool('APP_DEBUG', false);
    $message = $debug
        ? ($e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine())
        : 'Внутренняя ошибка сервера: ' . $e->getMessage();
    Response::error(500, 'INTERNAL_ERROR', $message, ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
}
