<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UrlCheckCacheRepository;
use App\Repositories\UserRepository;
use App\Services\CleanupService;
use App\Services\DateHelper;
use App\Services\JwtService;
use App\Services\UrlSecurity\RfRegistrySyncService;
use App\Services\UrlSecurity\UrlScannerService;

final class CronController
{
    public function __construct(
        private readonly CleanupService $cleanupService,
        private readonly Config $config,
        private readonly ?JwtService $jwt = null,
        private readonly ?UserRepository $userRepo = null,
        private readonly ?RfRegistrySyncService $rfSyncService = null,
        private readonly ?UrlCheckCacheRepository $cacheRepo = null,
        private readonly ?UrlScannerService $scannerService = null,
    ) {
    }

    /**
     * Эндпоинт периодической очистки базы данных для веб-крона или вызова администратором.
     * Защищён секретным ключом (CRON_SECRET_KEY) либо JWT-токеном администратора.
     */
    public function cleanup(Request $request): never
    {
        $this->ensureAuthorized($request);

        $dryRun = false;
        if (isset($request->query['dryRun'])) {
            $val = strtolower((string) $request->query['dryRun']);
            $dryRun = in_array($val, ['1', 'true', 'yes'], true);
        }

        $result = $this->cleanupService->run($dryRun);

        Response::json([
            'status' => 'ok',
            'message' => $dryRun ? 'Тестовая проверка очистки базы данных' : 'Очистка базы данных успешно выполнена',
            'result' => $result,
        ]);
    }

    /**
     * Эндпоинт периодической синхронизации безопасности ссылок для веб-крона.
     * Защищён секретным ключом (CRON_SECRET_KEY) либо JWT-токеном администратора.
     */
    public function securitySync(Request $request): never
    {
        $this->ensureAuthorized($request);

        @set_time_limit(180);
        @ini_set('memory_limit', '256M');

        $startTime = microtime(true);
        $syncRf = true;
        $pruneCache = true;
        $scanLinks = true;

        $hasSpecific = isset($request->query['syncRf']) || isset($request->query['pruneCache']) || isset($request->query['scanLinks']);
        if ($hasSpecific) {
            $syncRf = self::toBool($request->query['syncRf'] ?? null);
            $pruneCache = self::toBool($request->query['pruneCache'] ?? null);
            $scanLinks = self::toBool($request->query['scanLinks'] ?? null);
        }

        $report = [
            'timestamp' => DateHelper::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'rfRegistrySync' => null,
            'cachePruned' => null,
            'linkScan' => null,
        ];

        // 1. Синхронизация реестра РФ
        if ($syncRf && $this->rfSyncService !== null) {
            if ($this->config->getBool('RF_REGISTRY_SYNC_ENABLED', true)) {
                $customMirror = isset($request->query['mirrorUrl']) && is_string($request->query['mirrorUrl'])
                    ? trim($request->query['mirrorUrl'])
                    : null;
                $report['rfRegistrySync'] = $this->rfSyncService->sync($customMirror);
            } else {
                $report['rfRegistrySync'] = ['skipped' => true, 'reason' => 'RF_REGISTRY_SYNC_ENABLED is false'];
            }
        }

        // 2. Очистка кэша проверок
        if ($pruneCache && $this->cacheRepo !== null) {
            $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
            $deleted = $this->cacheRepo->pruneExpired($nowMysql);
            $report['cachePruned'] = ['deletedEntries' => $deleted];
        }

        // 3. Сканирование активных ссылок
        if ($scanLinks && $this->scannerService !== null) {
            $limit = min(500, max(1, (int) ($request->query['scanLimit'] ?? 100)));
            $report['linkScan'] = $this->scannerService->scanActiveShortcuts($limit);
        }

        $duration = round(microtime(true) - $startTime, 3);
        $report['durationSeconds'] = $duration;

        Response::json([
            'status' => 'ok',
            'message' => 'Синхронизация безопасности ссылок успешно выполнена',
            'result' => $report,
        ]);
    }

    private static function toBool(mixed $val): bool
    {
        if ($val === null) {
            return false;
        }
        $str = strtolower(trim((string) $val));
        return in_array($str, ['1', 'true', 'yes', 'on'], true);
    }

    private function ensureAuthorized(Request $request): void
    {
        $cronSecret = trim($this->config->get('CRON_SECRET_KEY', ''));

        // 1. Проверка по секретному ключу CRON_SECRET_KEY
        if ($cronSecret !== '') {
            $providedKey = $request->headers['x-cron-key'] ?? $request->query['key'] ?? null;
            if ($providedKey !== null && hash_equals($cronSecret, (string) $providedKey)) {
                return;
            }
        }

        // 2. Проверка JWT токена администратора (если передан)
        $authHeader = $request->headers['authorization'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ') && $this->jwt !== null && $this->userRepo !== null) {
            $token = trim(substr($authHeader, 7));
            $payload = $this->jwt->verify($token);
            if ($payload !== null) {
                $role = (string) ($payload['role'] ?? '');
                if ($role === 'admin') {
                    return;
                }
            }
        }

        // 3. Если CRON_SECRET_KEY не задан и это локальный запрос (127.0.0.1 / ::1)
        if ($cronSecret === '' && in_array($request->clientIp, ['127.0.0.1', '::1'], true)) {
            return;
        }

        throw ApiException::forbidden(
            'CRON_FORBIDDEN',
            'Доступ запрещён. Укажите верный CRON_SECRET_KEY в параметре ?key= или заголовке X-Cron-Key'
        );
    }
}
