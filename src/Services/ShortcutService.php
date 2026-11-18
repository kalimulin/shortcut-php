<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;
use App\Repositories\CampaignRepository;
use App\Repositories\ShortcutRepository;
use DateInterval;
use PDOException;

final class ShortcutService
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlTools $urlTools,
        private readonly UrlCheckService $urlCheckService,
        private readonly ShortcutRepository $shortcutRepo,
        private readonly CampaignRepository $campaignRepo,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listForUser(string $userId, array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $result = $this->shortcutRepo->listByUser($userId, $filters, $perPage, $offset);

        $items = array_map(fn (array $row): array => $this->serialize($row), $result['items']);
        return [
            'items' => $items,
            'meta' => [
                'total' => $result['total'],
                'count' => count($items),
                'page' => $page,
                'perPage' => $perPage,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getForUser(string $shortcutId, string $userId): array
    {
        if (!Uuid::isValid($shortcutId)) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
        $row = $this->shortcutRepo->findById($shortcutId, $userId);
        if ($row === null) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createForUser(string $userId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите название ссылки (title)');
        }
        if (mb_strlen($title) > 255) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Название ссылки не длиннее 255 символов');
        }

        $target = $this->urlTools->validateTargetFormat((string) ($input['target'] ?? ''));
        $this->urlCheckService->ensureAllowed($target);

        $compression = strtolower(trim((string) ($input['compression'] ?? 'full')));
        if (!in_array($compression, ['full', 'plain'], true)) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Недопустимый тип сжатия (compression: full | plain)');
        }

        $customCode = isset($input['shortcut']) && is_string($input['shortcut']) ? trim($input['shortcut']) : null;
        if ($compression === 'plain') {
            if ($customCode === null || preg_match(UrlTools::SHORTCUT_CODE_REGEX, $customCode) !== 1) {
                throw ApiException::badRequest(
                    'VALIDATION_ERROR',
                    'Для compression=plain нужен код из латиницы, цифр, "_" или "-" (до 64 символов)'
                );
            }
        }

        $campaignId = null;
        if (isset($input['campaignId']) && $input['campaignId'] !== '' && $input['campaignId'] !== null) {
            $campaignId = (string) $input['campaignId'];
            if (!Uuid::isValid($campaignId) || !$this->campaignRepo->existsForUser($campaignId, $userId)) {
                throw ApiException::unprocessable('SHORTCUT_CAMPAIGN_INVALID', 'Указанная кампания не найдена в аккаунте');
            }
        }

        $description = isset($input['description']) && $input['description'] !== null ? trim((string) $input['description']) : null;
        if ($description === '') {
            $description = null;
        }

        $tags = UrlTools::normalizeTags($input['tags'] ?? []);
        $utm = UrlTools::cleanUtm($input['utm'] ?? null);
        $channel = $this->normalizeChannel($input['channel'] ?? null);

        $expiresAtMysql = null;
        if (isset($input['expiresAt']) && $input['expiresAt'] !== null && $input['expiresAt'] !== '') {
            $parsedExp = DateHelper::parseUtc((string) $input['expiresAt']);
            if ($parsedExp === null) {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Некорректный формат даты expiresAt');
            }
            $expiresAtMysql = DateHelper::toMysqlUtc($parsedExp);
        }

        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $maxAttempts = $compression === 'full' ? max(1, $this->config->getInt('SHORTCUT_CREATE_MAX_ATTEMPTS', 5)) : 1;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $id = Uuid::v4();
            $code = $compression === 'full' ? Base62::fromUuid($id) : (string) $customCode;

            try {
                $this->shortcutRepo->create([
                    'id' => $id,
                    'user_id' => $userId,
                    'campaign_id' => $campaignId,
                    'code' => $code,
                    'title' => $title,
                    'description' => $description,
                    'target' => $target,
                    'reserve_url' => null,
                    'status' => 'active',
                    'block_reason' => null,
                    'source' => 'account',
                    'compression' => $compression,
                    'channel' => $channel,
                    'utm_json' => $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null,
                    'expires_at' => $expiresAtMysql,
                    'created_at' => $nowMysql,
                    'updated_at' => $nowMysql,
                ], $tags);

                return $this->getForUser($id, $userId);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    if ($compression === 'plain') {
                        throw ApiException::conflict('SHORTCUT_EXISTS', 'Короткая ссылка с таким кодом уже существует');
                    }
                    continue;
                }
                throw $e;
            }
        }

        throw ApiException::serviceUnavailable(
            'SHORTCUT_CODE_UNAVAILABLE',
            'Не удалось подобрать свободный код короткой ссылки. Повторите попытку.'
        );
    }

    /**
     * Создание гостевой (публичной) ссылки без авторизации.
     *
     * @return array<string, mixed>
     */
    public function createGuest(string $rawUrl): array
    {
        $target = $this->urlTools->validateTargetFormat($rawUrl);
        $check = $this->urlCheckService->ensureAllowed($target);

        $host = (string) (parse_url($target, PHP_URL_HOST) ?? 'ссылка');
        $title = mb_substr('Ссылка ' . $host, 0, 255);

        $now = DateHelper::nowUtc();
        $ttlHours = $this->config->getInt('PUBLIC_SHORTCUT_TTL_HOURS', 48);
        $expiresAt = $ttlHours > 0 ? $now->add(new DateInterval("PT{$ttlHours}H")) : null;

        $nowMysql = DateHelper::toMysqlUtc($now);
        $expiresMysql = $expiresAt !== null ? DateHelper::toMysqlUtc($expiresAt) : null;
        $maxAttempts = max(1, $this->config->getInt('SHORTCUT_CREATE_MAX_ATTEMPTS', 5));

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $id = Uuid::v4();
            $code = Base62::fromUuid($id);

            try {
                $this->shortcutRepo->create([
                    'id' => $id,
                    'user_id' => null,
                    'campaign_id' => null,
                    'code' => $code,
                    'title' => $title,
                    'description' => null,
                    'target' => $target,
                    'reserve_url' => null,
                    'status' => 'active',
                    'block_reason' => null,
                    'source' => 'public',
                    'compression' => 'full',
                    'channel' => null,
                    'utm_json' => null,
                    'expires_at' => $expiresMysql,
                    'created_at' => $nowMysql,
                    'updated_at' => $nowMysql,
                ], []);

                return [
                    'shortcutId' => $id,
                    'shortcut' => $code,
                    'shortUrl' => $this->urlTools->buildShortUrlFull($code),
                    'originalUrl' => $target,
                    'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
                    'expiresAt' => $expiresAt?->format('Y-m-d\TH:i:s\Z'),
                    'isSecure' => (bool) ($check['isSafe'] ?? true),
                    'securityScore' => (int) ($check['score'] ?? 100),
                ];
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    continue;
                }
                throw $e;
            }
        }

        throw ApiException::serviceUnavailable(
            'SHORTCUT_CODE_UNAVAILABLE',
            'Не удалось подобрать свободный код короткой ссылки. Повторите попытку.'
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateForUser(string $shortcutId, string $userId, string $userRole, array $input): array
    {
        if (!Uuid::isValid($shortcutId)) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }

        $fields = [];
        $tags = null;

        if (array_key_exists('title', $input) && $input['title'] !== null) {
            $title = trim((string) $input['title']);
            if ($title === '' || mb_strlen($title) > 255) {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Название ссылки должно быть от 1 до 255 символов');
            }
            $fields['title'] = $title;
        }

        if (array_key_exists('target', $input) && $input['target'] !== null) {
            $target = $this->urlTools->validateTargetFormat((string) $input['target']);
            $this->urlCheckService->ensureAllowed($target);
            $fields['target'] = $target;
        }

        if (array_key_exists('description', $input)) {
            $desc = $input['description'] !== null ? trim((string) $input['description']) : null;
            $fields['description'] = ($desc === '') ? null : $desc;
        }

        if (array_key_exists('campaignId', $input)) {
            $cid = $input['campaignId'];
            if ($cid === null || $cid === '') {
                $fields['campaign_id'] = null;
            } else {
                $cidStr = (string) $cid;
                if (!Uuid::isValid($cidStr) || !$this->campaignRepo->existsForUser($cidStr, $userId)) {
                    throw ApiException::unprocessable('SHORTCUT_CAMPAIGN_INVALID', 'Указанная кампания не найдена в аккаунте');
                }
                $fields['campaign_id'] = $cidStr;
            }
        }

        if (array_key_exists('utm', $input)) {
            $utm = UrlTools::cleanUtm($input['utm']);
            $fields['utm_json'] = $utm !== [] ? json_encode($utm, JSON_UNESCAPED_UNICODE) : null;
        }

        if (array_key_exists('expiresAt', $input)) {
            if ($input['expiresAt'] === null || $input['expiresAt'] === '') {
                $fields['expires_at'] = null;
            } else {
                $parsed = DateHelper::parseUtc((string) $input['expiresAt']);
                if ($parsed === null) {
                    throw ApiException::badRequest('VALIDATION_ERROR', 'Некорректный формат даты expiresAt');
                }
                $fields['expires_at'] = DateHelper::toMysqlUtc($parsed);
            }
        }

        if (array_key_exists('tags', $input)) {
            $tags = UrlTools::normalizeTags($input['tags']);
        }

        if (array_key_exists('channel', $input)) {
            $fields['channel'] = $this->normalizeChannel($input['channel']);
        }

        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $status = strtolower(trim((string) $input['status']));
            if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
                throw ApiException::badRequest('VALIDATION_ERROR', 'Допустимые статусы: active, inactive, blocked');
            }

            if ($status === 'active') {
                $fields['status'] = 'active';
                $fields['reserve_url'] = null;
                $fields['block_reason'] = null;
            } elseif ($status === 'inactive') {
                $fields['status'] = 'inactive';
                $fields['block_reason'] = null;
                if (array_key_exists('reserveUrl', $input)) {
                    $reserve = $input['reserveUrl'] !== null ? trim((string) $input['reserveUrl']) : null;
                    if ($reserve !== null && $reserve !== '') {
                        $reserve = $this->urlTools->validateTargetFormat($reserve);
                        $this->urlCheckService->ensureAllowed($reserve);
                        $fields['reserve_url'] = $reserve;
                    } else {
                        $fields['reserve_url'] = null;
                    }
                }
            } else {
                if ($userRole !== 'admin') {
                    throw ApiException::forbidden('Блокировка ссылки доступна только администратору');
                }
                $reason = isset($input['blockReason']) ? trim((string) $input['blockReason']) : '';
                if ($reason === '') {
                    throw ApiException::badRequest('SHORTCUT_BLOCK_REASON_REQUIRED', 'Укажите причину блокировки');
                }
                $fields['status'] = 'blocked';
                $fields['block_reason'] = mb_substr($reason, 0, 500);
                $fields['reserve_url'] = null;
            }
        } elseif (array_key_exists('reserveUrl', $input)) {
            $reserve = $input['reserveUrl'] !== null ? trim((string) $input['reserveUrl']) : null;
            if ($reserve !== null && $reserve !== '') {
                $reserve = $this->urlTools->validateTargetFormat($reserve);
                $this->urlCheckService->ensureAllowed($reserve);
                $fields['reserve_url'] = $reserve;
            } else {
                $fields['reserve_url'] = null;
            }
        }

        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $updated = $this->shortcutRepo->update($shortcutId, $userId, $fields, $tags, $nowMysql);
        if (!$updated) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }

        return $this->getForUser($shortcutId, $userId);
    }

    public function deleteForUser(string $shortcutId, string $userId): void
    {
        if (!Uuid::isValid($shortcutId)) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $deleted = $this->shortcutRepo->softDelete($shortcutId, $userId, $nowMysql);
        if (!$deleted) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
    }

    /** @return array<string, mixed> */
    public function claimPublicShortcut(string $code, string $userId): array
    {
        if (preg_match(UrlTools::SHORTCUT_CODE_REGEX, $code) !== 1) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $result = $this->shortcutRepo->claimByCode($code, $userId, $nowMysql);

        return match ($result['status']) {
            'ok' => $this->getForUser((string) $result['row']['id'], $userId),
            'already_claimed' => throw ApiException::conflict(
                'SHORTCUT_ALREADY_CLAIMED',
                'Короткая ссылка уже сохранена другим пользователем'
            ),
            default => throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена или истекла'),
        };
    }

    /** @return list<array{name: string, count: int}> */
    public function listTags(string $userId, ?string $campaignId, bool $showHidden): array
    {
        if ($campaignId !== null && !Uuid::isValid($campaignId)) {
            return [];
        }
        return $this->shortcutRepo->listTags($userId, $campaignId, $showHidden);
    }

    /** @return array<string, mixed> */
    public function getQrMetadata(string $code): array
    {
        if (preg_match(UrlTools::SHORTCUT_CODE_REGEX, $code) !== 1) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }
        $row = $this->shortcutRepo->findActiveByCode($code);
        if ($row === null) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Короткая ссылка не найдена');
        }

        return [
            'shortcut' => $code,
            'shortUrl' => $this->urlTools->buildShortUrlFull($code),
            'qrImageUrl' => '/api/public/qr/' . rawurlencode($code) . '/image',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function serialize(array $row): array
    {
        $code = (string) $row['code'];
        $utm = is_string($row['utm_json'] ?? null) ? json_decode($row['utm_json'], true) : null;

        return [
            'shortcutId' => (string) $row['id'],
            'title' => (string) $row['title'],
            'target' => (string) $row['target'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'shortUrl' => $this->urlTools->buildShortUrlFull($code),
            'shortcut' => $code,
            'status' => (string) ($row['status'] ?? 'active'),
            'blockReason' => $row['block_reason'] !== null ? (string) $row['block_reason'] : null,
            'reserveUrl' => $row['reserve_url'] !== null ? (string) $row['reserve_url'] : null,
            'clicksCount' => (int) ($row['clicks_count'] ?? 0),
            'tags' => array_values($row['tags'] ?? []),
            'campaignId' => $row['campaign_id'] !== null ? (string) $row['campaign_id'] : null,
            'campaign' => $row['campaign_name'] !== null ? (string) $row['campaign_name'] : null,
            'utm' => is_array($utm) && $utm !== [] ? $utm : null,
            'channel' => $row['channel'] !== null ? (string) $row['channel'] : null,
            'source' => (string) ($row['source'] ?? 'account'),
            'compression' => (string) ($row['compression'] ?? 'full'),
            'expiresAt' => DateHelper::mysqlToIso($row['expires_at'] ?? null),
            'createdAt' => DateHelper::mysqlToIso($row['created_at'] ?? null),
            'modifiedAt' => DateHelper::mysqlToIso($row['updated_at'] ?? null),
        ];
    }

    private function normalizeChannel(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $channel = trim((string) $raw);
        if ($channel === '') {
            return null;
        }
        if (mb_strlen($channel) > 64) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Название канала не длиннее 64 символов');
        }
        return $channel;
    }
}
