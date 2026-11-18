<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class CampaignRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listByUser(
        string $userId,
        int $limit,
        int $offset,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
    ): array {
        $where = ['c.user_id = :user_id', 'c.is_deleted = 0'];
        $params = [':user_id' => $userId];

        if ($createdAfter !== null) {
            $where[] = 'c.created_at >= :created_after';
            $params[':created_after'] = $createdAfter;
        }
        if ($createdBefore !== null) {
            $where[] = 'c.created_at <= :created_before';
            $params[':created_before'] = $createdBefore;
        }

        $whereSql = implode(' AND ', $where);
        $pdo = $this->db->pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns c WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sql = "SELECT c.* FROM campaigns c WHERE {$whereSql} ORDER BY c.created_at DESC, c.id ASC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return ['items' => $this->attachMetrics($rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id, string $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM campaigns WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $enriched = $this->attachMetrics([$row]);
        return $enriched[0];
    }

    public function existsForUser(string $id, string $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM campaigns WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO campaigns (id, user_id, name, description, tags_json, utm_json, starts_at, ends_at, is_deleted, created_at, updated_at)
             VALUES (:id, :user_id, :name, :description, :tags_json, :utm_json, :starts_at, :ends_at, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => $data['id'],
            ':user_id' => $data['user_id'],
            ':name' => $data['name'],
            ':description' => $data['description'],
            ':tags_json' => $data['tags_json'],
            ':utm_json' => $data['utm_json'],
            ':starts_at' => $data['starts_at'],
            ':ends_at' => $data['ends_at'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function update(string $id, string $userId, array $fields, string $nowMysql): void
    {
        $allowed = ['name', 'description', 'tags_json', 'utm_json', 'starts_at', 'ends_at'];
        $sets = [];
        $params = [':id' => $id, ':user_id' => $userId, ':updated_at' => $nowMysql];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE campaigns SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :user_id AND is_deleted = 0';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    public function softDeleteAndDetachShortcuts(string $id, string $userId, string $nowMysql): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE campaigns SET is_deleted = 1, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND is_deleted = 0'
            );
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':updated_at' => $nowMysql,
            ]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            $detach = $pdo->prepare(
                'UPDATE shortcuts SET campaign_id = NULL, updated_at = :updated_at
                 WHERE campaign_id = :campaign_id AND user_id = :user_id AND is_deleted = 0'
            );
            $detach->execute([
                ':campaign_id' => $id,
                ':user_id' => $userId,
                ':updated_at' => $nowMysql,
            ]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function countActiveShortcuts(string $campaignId, string $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM shortcuts WHERE campaign_id = :cid AND user_id = :uid AND is_deleted = 0'
        );
        $stmt->execute([':cid' => $campaignId, ':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $campaigns
     * @return list<array<string, mixed>>
     */
    private function attachMetrics(array $campaigns): array
    {
        if ($campaigns === []) {
            return [];
        }

        $ids = array_column($campaigns, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = $this->db->pdo();

        // Число активных ссылок в каждой кампании + суммарные клики по этим ссылкам
        $sql = "SELECT
                    s.campaign_id,
                    COUNT(DISTINCT s.id) AS links_count,
                    COALESCE(SUM(cc.clicks), 0) AS total_clicks
                FROM shortcuts s
                LEFT JOIN (
                    SELECT shortcut_id, COUNT(*) AS clicks
                    FROM click_events
                    GROUP BY shortcut_id
                ) cc ON cc.shortcut_id = s.id
                WHERE s.is_deleted = 0 AND s.campaign_id IN ({$placeholders})
                GROUP BY s.campaign_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        $metrics = [];
        foreach ($stmt->fetchAll() as $row) {
            $metrics[(string) $row['campaign_id']] = [
                'links_count' => (int) $row['links_count'],
                'total_clicks' => (int) $row['total_clicks'],
            ];
        }

        foreach ($campaigns as &$campaign) {
            $cid = (string) $campaign['id'];
            $campaign['links_count'] = $metrics[$cid]['links_count'] ?? 0;
            $campaign['total_clicks'] = $metrics[$cid]['total_clicks'] ?? 0;
        }
        unset($campaign);

        return $campaigns;
    }
}
