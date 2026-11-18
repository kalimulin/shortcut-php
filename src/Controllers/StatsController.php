<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\CampaignService;
use App\Services\ShortcutService;
use App\Services\StatsExportService;
use App\Services\StatsService;

final class StatsController
{
    public function __construct(
        private readonly StatsService $statsService,
        private readonly StatsExportService $exportService,
        private readonly ShortcutService $shortcutService,
        private readonly CampaignService $campaignService,
    ) {
    }

    public function accountStats(Request $request): never
    {
        $userId = (string) $request->getAttribute('userId');
        [$dateFrom, $dateTo, $days, $granularity] = $this->extractQuery($request);

        Response::json($this->statsService->accountStats($userId, $dateFrom, $dateTo, $days, $granularity));
    }

    /** @param array<string, string> $params */
    public function shortcutStats(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $shortcutId = $params['id'] ?? '';
        $this->shortcutService->getForUser($shortcutId, $userId);

        [$dateFrom, $dateTo, $days, $granularity] = $this->extractQuery($request);
        Response::json($this->statsService->shortcutStats($userId, $shortcutId, $dateFrom, $dateTo, $days, $granularity));
    }

    /** @param array<string, string> $params */
    public function campaignStats(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $campaignId = $params['id'] ?? '';
        $this->campaignService->getForUser($campaignId, $userId);

        [$dateFrom, $dateTo, $days, $granularity] = $this->extractQuery($request);
        $linksCount = $this->campaignService->countActiveShortcuts($campaignId, $userId);

        Response::json($this->statsService->campaignStats(
            $userId,
            $campaignId,
            $dateFrom,
            $dateTo,
            $days,
            $granularity,
            $linksCount,
        ));
    }

    /** @param array<string, string> $params */
    public function exportShortcut(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $shortcutId = $params['id'] ?? '';
        $shortcut = $this->shortcutService->getForUser($shortcutId, $userId);

        [$dateFrom, $dateTo, $days, $granularity] = $this->extractQuery($request);
        $format = strtolower((string) $request->queryParam('format', 'csv'));
        $stats = $this->statsService->shortcutStats($userId, $shortcutId, $dateFrom, $dateTo, $days, $granularity);

        $this->sendFile($stats, $format, 'shortcut', (string) ($shortcut['title'] ?? ''));
    }

    /** @param array<string, string> $params */
    public function exportCampaign(Request $request, array $params): never
    {
        $userId = (string) $request->getAttribute('userId');
        $campaignId = $params['id'] ?? '';
        $campaign = $this->campaignService->getForUser($campaignId, $userId);

        [$dateFrom, $dateTo, $days, $granularity] = $this->extractQuery($request);
        $format = strtolower((string) $request->queryParam('format', 'csv'));
        $linksCount = $this->campaignService->countActiveShortcuts($campaignId, $userId);
        $stats = $this->statsService->campaignStats(
            $userId,
            $campaignId,
            $dateFrom,
            $dateTo,
            $days,
            $granularity,
            $linksCount,
        );

        $this->sendFile($stats, $format, 'campaign', (string) ($campaign['name'] ?? ''));
    }

    /** @param array<string, mixed> $stats */
    private function sendFile(array $stats, string $format, string $kind, string $title): never
    {
        $rendered = $this->exportService->render($stats, $format);
        $filename = $this->exportService->reportDownloadName($kind, $title);
        $disposition = $this->exportService->contentDisposition($filename, $rendered['extension']);

        Response::raw($rendered['body'], $rendered['contentType'], 200, [
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int, 3: string} */
    private function extractQuery(Request $request): array
    {
        $dateFrom = $request->queryParam('dateFrom') ?? $request->queryParam('date_from');
        $dateTo = $request->queryParam('dateTo') ?? $request->queryParam('date_to');
        $rawDays = $request->queryParam('days');
        $days = ($rawDays !== null && is_numeric($rawDays)) ? (int) $rawDays : null;
        $granularity = strtolower((string) $request->queryParam('granularity', 'day'));

        return [$dateFrom, $dateTo, $days, $granularity];
    }
}
