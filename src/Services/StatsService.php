<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;
use App\Repositories\ClickRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class StatsService
{
    public function __construct(
        private readonly Config $config,
        private readonly ClickRepository $clickRepo,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function accountStats(
        string $userId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $days,
        string $granularity,
    ): array {
        return $this->buildReport($userId, $dateFrom, $dateTo, $days, $granularity, null, null, true, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function shortcutStats(
        string $userId,
        string $shortcutId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $days,
        string $granularity,
    ): array {
        return $this->buildReport($userId, $dateFrom, $dateTo, $days, $granularity, $shortcutId, null, false, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function campaignStats(
        string $userId,
        string $campaignId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $days,
        string $granularity,
        int $linksCount,
    ): array {
        return $this->buildReport($userId, $dateFrom, $dateTo, $days, $granularity, null, $campaignId, true, $linksCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(
        string $userId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $days,
        string $granularity,
        ?string $shortcutId,
        ?string $campaignId,
        bool $withShortcuts,
        ?int $linksCount,
    ): array {
        $gran = strtolower($granularity) === 'hour' ? 'hour' : 'day';
        [$start, $end] = $this->resolveRange($dateFrom, $dateTo, $days);

        $tzSpec = $this->config->get('REPORT_TIMEZONE', '+03:00');
        try {
            $reportTz = new DateTimeZone($tzSpec);
        } catch (Throwable) {
            $reportTz = new DateTimeZone('+03:00');
        }
        $tzOffsetMinutes = intdiv($reportTz->getOffset($end), 60);

        $startMysql = DateHelper::toMysqlUtcMillis($start);
        $endMysql = DateHelper::toMysqlUtcMillis($end);

        $totals = $this->clickRepo->getTotals($userId, $startMysql, $endMysql, $shortcutId, $campaignId);
        $rawSeries = $this->clickRepo->getSeries($userId, $startMysql, $endMysql, $gran, $tzOffsetMinutes, $shortcutId, $campaignId);

        $series = [];
        $byDay = [];
        foreach ($this->buildPeriodKeys($start, $end, $gran, $reportTz) as $entry) {
            $bucket = $rawSeries[$entry['sqlKey']] ?? ['clicks' => 0, 'uniqueVisitors' => 0];
            $series[] = [
                'period' => $entry['label'],
                'clicks' => $bucket['clicks'],
                'uniqueVisitors' => $bucket['uniqueVisitors'],
            ];
            if ($gran === 'day') {
                $byDay[] = [
                    'date' => $entry['label'],
                    'value' => $bucket['clicks'],
                ];
            }
        }

        $report = [
            'dateFrom' => $start->format('Y-m-d\TH:i:s\Z'),
            'dateTo' => $end->format('Y-m-d\TH:i:s\Z'),
            'granularity' => $gran,
            'total' => $totals['total'],
            'unique' => $totals['unique'],
            'series' => $series,
        ];

        if ($gran === 'day') {
            $report['byDay'] = $byDay;
        }
        if ($linksCount !== null) {
            $report['linksCount'] = $linksCount;
        }
        if ($withShortcuts) {
            $report['shortcuts'] = $this->clickRepo->getShortcutBreakdown($userId, $startMysql, $endMysql, $campaignId);
        }

        return $report;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function resolveRange(?string $dateFrom, ?string $dateTo, ?int $days): array
    {
        $end = DateHelper::parseUtc($dateTo) ?? DateHelper::nowUtc();
        $defaultDays = max(1, $this->config->getInt('CLICK_STATS_DEFAULT_DAYS', 30));
        $effectiveDays = ($days !== null && $days >= 1 && $days <= 366) ? $days : $defaultDays;

        $start = DateHelper::parseUtc($dateFrom) ?? $end->sub(new DateInterval("P{$effectiveDays}D"));
        if ($start >= $end) {
            throw ApiException::badRequest('INVALID_DATE_RANGE', 'dateFrom должен быть раньше dateTo');
        }

        // Ограничиваем максимальный диапазон 366 днями, чтобы защититься от слишком больших циклов
        $maxEnd = $start->add(new DateInterval('P366D'));
        if ($end > $maxEnd) {
            $start = $end->sub(new DateInterval('P366D'));
        }

        return [$start, $end];
    }

    /**
     * Шаг — час: границы суток в поясе отчёта (например, UTC+3) не совпадают с UTC.
     *
     * @return list<array{sqlKey: string, label: string}>
     */
    private function buildPeriodKeys(
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
        string $granularity,
        DateTimeZone $reportTz,
    ): array {
        $cursor = $startUtc->setTime((int) $startUtc->format('H'), 0, 0);
        $step = new DateInterval('PT1H');
        $seen = [];
        $keys = [];

        while ($cursor < $endUtc) {
            $local = $cursor->setTimezone($reportTz);
            if ($granularity === 'day') {
                $sqlKey = $local->format('Y-m-d');
                $label = $sqlKey;
            } else {
                $sqlKey = $local->format('Y-m-d H:00:00');
                $label = $local->format('Y-m-d\TH:00:00P');
            }

            if (!isset($seen[$sqlKey])) {
                $seen[$sqlKey] = true;
                $keys[] = ['sqlKey' => $sqlKey, 'label' => $label];
            }
            $cursor = $cursor->add($step);
        }

        return $keys;
    }
}
