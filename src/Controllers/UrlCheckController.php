<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\RfRegistryRepository;
use App\Services\DateHelper;
use App\Services\UrlCheckService;
use App\Services\UrlSecurity\RfRegistrySyncService;

final class UrlCheckController
{
    public function __construct(
        private readonly UrlCheckService $urlCheckService,
        private readonly ?RfRegistryRepository $rfRepo = null,
        private readonly ?RfRegistrySyncService $rfSyncService = null,
    ) {
    }

    public function check(Request $request): never
    {
        $body = $request->json();
        $url = isset($body['url']) && is_string($body['url']) ? $body['url'] : '';
        Response::json($this->urlCheckService->check($url));
    }

    public function listBlacklist(Request $request): never
    {
        $items = $this->urlCheckService->listBlacklist();
        Response::json([
            'items' => $items,
            'meta' => [
                'total' => count($items),
                'count' => count($items),
            ],
        ]);
    }

    public function addBlacklist(Request $request): never
    {
        $body = $request->json();
        $domain = isset($body['domain']) && is_string($body['domain']) ? $body['domain'] : '';
        if (trim($domain) === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите домен (domain)');
        }
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;
        $userId = (string) $request->getAttribute('userId');

        $entry = $this->urlCheckService->addBlacklistEntry($domain, $reason, $userId);
        Response::json($entry, 201);
    }

    /** @param array<string, string> $params */
    public function deleteBlacklist(Request $request, array $params): never
    {
        $this->urlCheckService->removeBlacklistEntry($params['id'] ?? '');
        Response::json(['ok' => true]);
    }

    public function listRfBanned(Request $request): never
    {
        if ($this->rfRepo === null) {
            throw ApiException::internal('RF registry not initialized');
        }

        $query = $request->query('q');
        $limit = min(200, max(1, (int) ($request->query('limit') ?: 50)));
        $items = $this->rfRepo->listRecent($limit, $query);
        $stats = $this->rfRepo->getStats();

        Response::json([
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'type' => (string) $row['entry_type'],
                'value' => (string) $row['value'],
                'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
                'source' => (string) $row['source'],
                'createdAt' => DateHelper::mysqlToIso((string) $row['created_at']),
            ], $items),
            'stats' => $stats,
        ]);
    }

    public function getRfStats(Request $request): never
    {
        if ($this->rfRepo === null) {
            throw ApiException::internal('RF registry not initialized');
        }

        Response::json($this->rfRepo->getStats());
    }

    public function addRfBanned(Request $request): never
    {
        if ($this->rfRepo === null) {
            throw ApiException::internal('RF registry not initialized');
        }

        $body = $request->json();
        $type = isset($body['type']) && is_string($body['type']) ? strtolower(trim($body['type'])) : 'domain';
        if (!in_array($type, ['domain', 'url', 'ip'], true)) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Некорректный тип: domain, url или ip');
        }

        $value = isset($body['value']) && is_string($body['value']) ? trim($body['value']) : '';
        if ($value === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите значение (value)');
        }

        $comment = isset($body['comment']) && is_string($body['comment']) ? trim($body['comment']) : null;
        $now = DateHelper::nowUtc();
        $id = $this->rfRepo->createManual($type, $value, $comment, DateHelper::toMysqlUtc($now));

        Response::json([
            'id' => $id,
            'type' => $type,
            'value' => $value,
            'comment' => $comment,
            'source' => 'manual',
            'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
        ], 201);
    }

    /** @param array<string, string> $params */
    public function deleteRfBanned(Request $request, array $params): never
    {
        if ($this->rfRepo === null) {
            throw ApiException::internal('RF registry not initialized');
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Некорректный ID записи');
        }

        $deleted = $this->rfRepo->delete($id);
        if (!$deleted) {
            throw ApiException::notFound('ENTRY_NOT_FOUND', 'Запись не найдена');
        }

        Response::json(['ok' => true]);
    }

    public function syncRfRegistry(Request $request): never
    {
        if ($this->rfSyncService === null) {
            throw ApiException::internal('RF sync service not initialized');
        }

        $body = $request->json();
        $mirrorUrl = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : null;
        $result = $this->rfSyncService->sync($mirrorUrl);

        Response::json($result);
    }
}
