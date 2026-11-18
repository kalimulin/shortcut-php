<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Services\UrlCheckService;

final class UrlCheckController
{
    public function __construct(private readonly UrlCheckService $urlCheckService)
    {
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
}
