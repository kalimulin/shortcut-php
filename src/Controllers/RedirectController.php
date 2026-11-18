<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Repositories\ClickRepository;
use App\Repositories\ShortcutRepository;
use App\Services\DateHelper;
use App\Services\UrlCheckService;
use App\Services\UrlTools;
use Throwable;

final class RedirectController
{
    public function __construct(
        private readonly Config $config,
        private readonly ShortcutRepository $shortcutRepo,
        private readonly ClickRepository $clickRepo,
        private readonly UrlCheckService $urlCheckService,
    ) {
    }

    public function handle(string $code): never
    {
        $visitorId = $this->resolveVisitorId();
        $this->sendCommonHeaders($visitorId);

        $code = trim($code);
        if ($code === '' || preg_match(UrlTools::SHORTCUT_CODE_REGEX, $code) !== 1) {
            $this->redirect303('/links/errors/not-found');
        }

        $row = $this->shortcutRepo->findActiveByCode($code);
        if ($row === null) {
            $this->redirect303('/links/errors/not-found');
        }

        $now = DateHelper::nowUtc();
        $nowMysql = DateHelper::toMysqlUtc($now);
        if ($row['expires_at'] !== null && (string) $row['expires_at'] <= $nowMysql) {
            $this->redirect303('/links/errors/not-found');
        }

        $status = (string) ($row['status'] ?? 'active');
        if ($status === 'blocked') {
            $this->redirect303('/links/errors/blocked');
        }

        $destination = null;
        if ($status === 'inactive') {
            $reserve = trim((string) ($row['reserve_url'] ?? ''));
            if ($reserve !== '') {
                $reserveCheck = $this->urlCheckService->check($reserve);
                if (empty($reserveCheck['isBlocked']) && empty($reserveCheck['isInvalid'])) {
                    $destination = $reserve;
                }
            }
            if ($destination === null) {
                $this->redirect303('/links/errors/inactive');
            }
        } else {
            $target = (string) $row['target'];
            $check = $this->urlCheckService->check($target);
            if (!empty($check['isBlocked']) || !empty($check['isInvalid'])) {
                $this->redirect303('/links/errors/blocked');
            }

            $campaignUtm = null;
            if (empty($row['campaign_is_deleted']) && is_string($row['campaign_utm_json'] ?? null)) {
                $decoded = json_decode($row['campaign_utm_json'], true);
                if (is_array($decoded)) {
                    $campaignUtm = $decoded;
                }
            }

            $linkUtm = null;
            if (is_string($row['utm_json'] ?? null)) {
                $decoded = json_decode($row['utm_json'], true);
                if (is_array($decoded)) {
                    $linkUtm = $decoded;
                }
            }

            $destination = UrlTools::buildTarget($target, $campaignUtm, $linkUtm);
        }

        // Отдаём 302 немедленно, а запись клика выполняем после сброса ответа в PHP-FPM (если доступно)
        http_response_code(302);
        header('Location: ' . $destination);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $referer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

            $this->clickRepo->recordClick(
                (string) $row['id'],
                $row['campaign_id'] !== null ? (string) $row['campaign_id'] : null,
                $row['user_id'] !== null ? (string) $row['user_id'] : null,
                $code,
                $visitorId,
                $referer,
                $userAgent,
                DateHelper::toMysqlUtcMillis($now),
            );
        } catch (Throwable) {
            // Ошибка записи аналитики не должна ломать переход пользователя
        }

        exit;
    }

    private function resolveVisitorId(): string
    {
        $cookie = $_COOKIE['rsv_vid'] ?? '';
        if (is_string($cookie) && preg_match('/^[0-9a-f]{32}$/', $cookie) === 1) {
            return $cookie;
        }
        return bin2hex(random_bytes(16));
    }

    private function sendCommonHeaders(string $visitorId): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie('rsv_vid', $visitorId, [
            'expires' => time() + 31536000,
            'path' => '/s/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Cache-Control: no-store');
    }

    private function redirect303(string $relativePath): never
    {
        $url = rtrim($this->config->appUrl(), '/') . $relativePath;
        http_response_code(303);
        header('Location: ' . $url);
        exit;
    }
}
