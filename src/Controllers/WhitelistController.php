<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\WhitelistRepository;
use App\Services\DateHelper;
use App\Services\Uuid;

final class WhitelistController
{
    public function __construct(private readonly WhitelistRepository $whitelistRepo)
    {
    }

    public function list(Request $request): never
    {
        $items = array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'pattern' => (string) $row['pattern'],
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'createdBy' => $row['created_by'] !== null ? (string) $row['created_by'] : null,
            'createdAt' => DateHelper::mysqlToIso((string) $row['created_at']),
        ], $this->whitelistRepo->listAll());

        Response::json([
            'items' => $items,
            'meta' => [
                'total' => count($items),
                'count' => count($items),
            ],
        ]);
    }

    public function add(Request $request): never
    {
        $body = $request->json();
        $pattern = isset($body['pattern']) && is_string($body['pattern']) ? trim($body['pattern']) : '';
        if ($pattern === '') {
            throw ApiException::badRequest('VALIDATION_ERROR', 'Укажите шаблон или домен (pattern)');
        }

        if ($this->whitelistRepo->existsByPattern($pattern)) {
            throw ApiException::conflict('WHITELIST_ENTRY_EXISTS', 'Шаблон уже есть в белом списке');
        }

        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        $userId = (string) $request->getAttribute('userId');
        $id = Uuid::v4();
        $now = DateHelper::nowUtc();

        $this->whitelistRepo->create($id, $pattern, $reason, $userId, DateHelper::toMysqlUtc($now));

        Response::json([
            'id' => $id,
            'pattern' => $pattern,
            'reason' => $reason,
            'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
        ], 201);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): never
    {
        $id = $params['id'] ?? '';
        if (!Uuid::isValid($id)) {
            throw ApiException::notFound('WHITELIST_ENTRY_NOT_FOUND', 'Запись белого списка не найдена');
        }

        $deleted = $this->whitelistRepo->delete($id);
        if (!$deleted) {
            throw ApiException::notFound('WHITELIST_ENTRY_NOT_FOUND', 'Запись белого списка не найдена');
        }

        Response::json(['ok' => true]);
    }
}
