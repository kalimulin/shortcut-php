<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class ClickRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function recordClick(
        string $shortcutId,
        ?string $campaignId,
        ?string $userId,
        string $code,
        string $visitorId,
        ?string $referer,
        ?string $userAgent,
        string $clickedAtMysqlMillis,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO click_events (
                shortcut_id, campaign_id, user_id, code, visitor_id, referer, user_agent, clicked_at
             ) VALUES (
                :shortcut_id, :campaign_id, :user_id, :code, :visitor_id, :referer, :user_agent, :clicked_at
             )'
        );
        $stmt->execute([
            ':shortcut_id' => $shortcutId,
            ':campaign_id' => $campaignId,
            ':user_id' => $userId,
            ':code' => $code,
            ':visitor_id' => $visitorId,
            ':referer' => $referer !== null ? mb_substr($referer, 0, 1024) : null,
            ':user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            ':clicked_at' => $clickedAtMysqlMillis,
        ]);
    }

    /**
     * @return array{total: int, unique: int}
     */
    public function getTotals(
        string $userId,
        string $startUtc,
        string $endUtc,
        ?string $shortcutId = null,
        ?string $campaignId = null,
    ): array {
        [$whereSql, $params] = $this->buildRangeWhere($userId, $startUtc, $endUtc, $shortcutId, $campaignId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total_clicks, COUNT(DISTINCT visitor_id) AS unique_visitors
             FROM click_events
             WHERE {$whereSql}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total' => (int) ($row['total_clicks'] ?? 0),
            'unique' => (int) ($row['unique_visitors'] ?? 0),
        ];
    }

    /**
     * Возвращает агрегацию по дням ('%Y-%m-%d') или часам ('%Y-%m-%d %H:00:00') со смещением tzOffsetMinutes.
     *
     * @return array<string, array{clicks: int, uniqueVisitors: int}>
     */
    public function getSeries(
        string $userId,
        string $startUtc,
        string $endUtc,
        string $granularity,
        int $tzOffsetMinutes,
        ?string $shortcutId = null,
        ?string $campaignId = null,
    ): array {
        [$whereSql, $params] = $this->buildRangeWhere($userId, $startUtc, $endUtc, $shortcutId, $campaignId);
        $format = $granularity === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        $params[':tz_offset'] = $tzOffsetMinutes;

        $sql = "SELECT
                    DATE_FORMAT(DATE_ADD(clicked_at, INTERVAL :tz_offset MINUTE), '{$format}') AS period_key,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT visitor_id) AS unique_visitors
                FROM click_events
                WHERE {$whereSql}
                GROUP BY period_key
                ORDER BY period_key ASC";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['period_key']] = [
                'clicks' => (int) $row['clicks'],
                'uniqueVisitors' => (int) $row['unique_visitors'],
            ];
        }
        return $map;
    }

    /**
     * Разбивка кликов по ссылкам (для кампании и обзора аккаунта).
     *
     * @return list<array{shortcutId: string, shortcut: string, clicks: int, uniqueVisitors: int}>
     */
    public function getShortcutBreakdown(
        string $userId,
        string $startUtc,
        string $endUtc,
        ?string $campaignId = null,
    ): array {
        [$whereSql, $params] = $this->buildRangeWhere($userId, $startUtc, $endUtc, null, $campaignId);

        $sql = "SELECT
                    shortcut_id,
                    MAX(code) AS shortcut,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT visitor_id) AS unique_visitors
                FROM click_events
                WHERE {$whereSql}
                GROUP BY shortcut_id
                ORDER BY clicks DESC, shortcut_id ASC";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = [
                'shortcutId' => (string) $row['shortcut_id'],
                'shortcut' => (string) $row['shortcut'],
                'clicks' => (int) $row['clicks'],
                'uniqueVisitors' => (int) $row['unique_visitors'],
            ];
        }
        return $result;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildRangeWhere(
        string $userId,
        string $startUtc,
        string $endUtc,
        ?string $shortcutId,
        ?string $campaignId,
    ): array {
        $where = ['user_id = :user_id', 'clicked_at >= :start_utc', 'clicked_at < :end_utc'];
        $params = [
            ':user_id' => $userId,
            ':start_utc' => $startUtc,
            ':end_utc' => $endUtc,
        ];

        if ($shortcutId !== null) {
            $where[] = 'shortcut_id = :shortcut_id';
            $params[':shortcut_id'] = $shortcutId;
        }
        if ($campaignId !== null) {
            $where[] = 'campaign_id = :campaign_id';
            $params[':campaign_id'] = $campaignId;
        }

        return [implode(' AND ', $where), $params];
    }
}
