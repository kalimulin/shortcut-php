<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Services\CampaignService;
use App\Services\DateHelper;
use App\Services\ShortcutService;

final class CampaignController
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly ShortcutService $shortcutService,
    ) {
    }

    public function list(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $page = $request->queryInt('page', 1, 1, 10000);
        $perPage = $request->queryInt('perPage', $request->queryInt('per_page', 50, 1, 200), 1, 200);

        $createdAfter = DateHelper::parseUtc($request->queryParam('createdAfter'));
        $createdBefore = DateHelper::parseUtc($request->queryParam('createdBefore'));

        Response::json($this->campaignService->listForUser(
            $userId,
            $page,
            $perPage,
            $createdAfter !== null ? DateHelper::toMysqlUtc($createdAfter) : null,
            $createdBefore !== null ? DateHelper::toMysqlUtc($createdBefore) : null,
        ));
    }

    /** @param array<string, string> $params */
    public function get(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        Response::json($this->campaignService->getForUser($params['id'] ?? '', $userId));
    }

    public function create(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        $created = $this->campaignService->createForUser($userId, $request->json());
        Response::json($created, 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $updated = $this->campaignService->updateForUser($params['id'] ?? '', $userId, $request->json());
        Response::json($updated);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $this->campaignService->deleteForUser($params['id'] ?? '', $userId);
        Response::json(['ok' => true]);
    }

    /** @param array<string, string> $params */
    public function listShortcuts(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $campaignId = $params['id'] ?? '';
        // Убеждаемся, что кампания существует и принадлежит пользователю
        $this->campaignService->getForUser($campaignId, $userId);

        $page = $request->queryInt('page', 1, 1, 10000);
        $perPage = $request->queryInt('perPage', $request->queryInt('per_page', 50, 1, 200), 1, 200);

        Response::json($this->shortcutService->listForUser(
            $userId,
            ['campaignId' => $campaignId, 'showHidden' => true],
            $page,
            $perPage,
        ));
    }

    /** @param array<string, string> $params */
    public function addShortcut(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $userRole = (string) $request->getAttribute('userRole', 'user');
        $campaignId = $params['id'] ?? '';
        $this->campaignService->getForUser($campaignId, $userId);

        $body = $request->json();
        $shortcutId = isset($body['shortcutId']) && is_string($body['shortcutId']) ? $body['shortcutId'] : '';
        if ($shortcutId === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите shortcutId');
        }

        $updated = $this->shortcutService->updateForUser($shortcutId, $userId, $userRole, ['campaignId' => $campaignId]);
        Response::json($updated);
    }

    /** @param array<string, string> $params */
    public function removeShortcut(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $userRole = (string) $request->getAttribute('userRole', 'user');
        $campaignId = $params['id'] ?? '';
        $shortcutId = $params['shortcutId'] ?? '';

        $shortcut = $this->shortcutService->getForUser($shortcutId, $userId);
        if (($shortcut['campaignId'] ?? null) !== $campaignId) {
            throw ApiException::notFound('SHORTCUT_NOT_FOUND', 'Ссылка не привязана к этой кампании');
        }

        $this->shortcutService->updateForUser($shortcutId, $userId, $userRole, ['campaignId' => null]);
        Response::json(['ok' => true]);
    }
}
