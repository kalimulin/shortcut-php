<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Services\CleanupService;
use App\Services\JwtService;

final class CronController
{
    public function __construct(
        private readonly CleanupService $cleanupService,
        private readonly Config $config,
        private readonly ?JwtService $jwt = null,
        private readonly ?UserRepository $userRepo = null,
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
