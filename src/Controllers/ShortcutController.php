<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Services\DateHelper;
use App\Services\ShortcutService;

final class ShortcutController
{
    public function __construct(private readonly ShortcutService $shortcutService)
    {
    }

    public function list(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $page = $request->queryInt('page', 1, 1, 10000);
        $perPage = $request->queryInt('perPage', $request->queryInt('per_page', 50, 1, 200), 1, 200);

        $filters = [
            'campaignId' => $request->queryParam('campaignId') ?? $request->queryParam('campaign_id'),
            'status' => $request->queryParam('status'),
            'tag' => $request->queryParam('tag'),
            'q' => $request->queryParam('q'),
            'showHidden' => $request->queryBool('showHidden') ?? true,
            'hiddenOnly' => $request->queryBool('hiddenOnly') ?? false,
            'sort' => $request->queryParam('sort', 'createdAt'),
            'order' => $request->queryParam('order', 'desc'),
            'createdAfter' => $this->toMysqlFilter($request->queryParam('createdAfter')),
            'createdBefore' => $this->toMysqlFilter($request->queryParam('createdBefore')),
            'modifiedAfter' => $this->toMysqlFilter($request->queryParam('modifiedAfter')),
            'modifiedBefore' => $this->toMysqlFilter($request->queryParam('modifiedBefore')),
        ];

        Response::json($this->shortcutService->listForUser($userId, $filters, $page, $perPage));
    }

    /** @param array<string, string> $params */
    public function get(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        Response::json($this->shortcutService->getForUser($params['id'] ?? '', $userId));
    }

    public function create(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $created = $this->shortcutService->createForUser($userId, $request->json());
        Response::json($created, 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $userRole = (string) $request->getAttribute('userRole', 'user');
        $updated = $this->shortcutService->updateForUser($params['id'] ?? '', $userId, $userRole, $request->json());
        Response::json($updated);
    }

    /** @param array<string, string> $params */
    public function setStatus(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $userRole = (string) $request->getAttribute('userRole', 'user');
        $body = $request->json();
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Допустимые статусы для этой ручки: active или inactive');
        }

        $patch = ['status' => $status];
        if (array_key_exists('reserveUrl', $body)) {
            $patch['reserveUrl'] = $body['reserveUrl'];
        }

        $updated = $this->shortcutService->updateForUser($params['id'] ?? '', $userId, $userRole, $patch);
        Response::json($updated);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $this->shortcutService->deleteForUser($params['id'] ?? '', $userId);
        Response::json(['ok' => true]);
    }

    /** @param array<string, string> $params */
    public function claim(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $claimed = $this->shortcutService->claimPublicShortcut($params['code'] ?? '', $userId);
        Response::json($claimed);
    }

    public function listTags(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $campaignId = $request->queryParam('campaignId') ?? $request->queryParam('campaign_id');
        $showHidden = $request->queryBool('showHidden') ?? true;

        $items = $this->shortcutService->listTags($userId, $campaignId, $showHidden);
        Response::json([
            'items' => $items,
            'meta' => [
                'total' => count($items),
                'count' => count($items),
            ],
        ]);
    }

    private function toMysqlFilter(?string $raw): ?string
    {
        $parsed = DateHelper::parseUtc($raw);
        return $parsed !== null ? DateHelper::toMysqlUtc($parsed) : null;
    }
}
