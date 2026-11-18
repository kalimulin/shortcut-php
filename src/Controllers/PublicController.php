<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\QrSvgService;
use App\Services\ShortcutService;
use App\Services\UrlCheckService;

final class PublicController
{
    public function __construct(
        private readonly ShortcutService $shortcutService,
        private readonly UrlCheckService $urlCheckService,
        private readonly QrSvgService $qrSvgService,
    ) {
    }

    public function createShortcut(Request $request): never
    {
        $body = $request->json();
        $url = isset($body['url']) && is_string($body['url']) ? $body['url'] : '';
        $created = $this->shortcutService->createGuest($url);
        Response::json($created, 201);
    }

    public function checkUrl(Request $request): never
    {
        $body = $request->json();
        $url = isset($body['url']) && is_string($body['url']) ? $body['url'] : '';
        Response::json($this->urlCheckService->check($url));
    }

    /** @param array<string, string> $params */
    public function getQrMeta(Request $request, array $params): never
    {
        $meta = $this->shortcutService->getQrMetadata($params['code'] ?? '');
        Response::json($meta);
    }

    /** @param array<string, string> $params */
    public function getQrImage(Request $request, array $params): never
    {
        $meta = $this->shortcutService->getQrMetadata($params['code'] ?? '');
        $svg = $this->qrSvgService->generateSvg((string) $meta['shortUrl']);

        Response::raw($svg, 'image/svg+xml; charset=utf-8', 200, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
