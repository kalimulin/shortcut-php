<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ShortcutRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listByUser(string $userId, array $filters, int $limit, int $offset): array
    {
        $where = ['s.user_id = :user_id', 's.is_deleted = 0'];
        $params = [':user_id' => $userId];

        if (!empty($filters['campaignId'])) {
            $where[] = 's.campaign_id = :campaign_id';
            $params[':campaign_id'] = (string) $filters['campaignId'];
        }

        if (!empty($filters['status'])) {
            $where[] = 's.status = :status';
            $params[':status'] = (string) $filters['status'];
        } elseif (!empty($filters['hiddenOnly'])) {
            $where[] = "s.status = 'inactive'";
        } elseif (isset($filters['showHidden']) && $filters['showHidden'] === false) {
            $where[] = "s.status = 'active'";
        }

        if (!empty($filters['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM shortcut_tags st WHERE st.shortcut_id = s.id AND st.tag = :tag)';
            $params[':tag'] = (string) $filters['tag'];
        }

        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $where[] = '(s.title LIKE :q1 OR s.target LIKE :q2 OR s.code LIKE :q3 OR EXISTS (SELECT 1 FROM shortcut_tags st2 WHERE st2.shortcut_id = s.id AND st2.tag LIKE :q4))';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }

        if (!empty($filters['createdAfter'])) {
            $where[] = 's.created_at >= :created_after';
            $params[':created_after'] = $filters['createdAfter'];
        }
        if (!empty($filters['createdBefore'])) {
            $where[] = 's.created_at <= :created_before';
            $params[':created_before'] = $filters['createdBefore'];
        }
        if (!empty($filters['modifiedAfter'])) {
            $where[] = 's.updated_at >= :modified_after';
            $params[':modified_after'] = $filters['modifiedAfter'];
        }
        if (!empty($filters['modifiedBefore'])) {
            $where[] = 's.updated_at <= :modified_before';
            $params[':modified_before'] = $filters['modifiedBefore'];
        }

        $whereSql = implode(' AND ', $where);
        $pdo = $this->db->pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM shortcuts s WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $direction = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sort = (string) ($filters['sort'] ?? 'createdAt');

        $orderSql = match ($sort) {
            'clicks' => "clicks_count {$direction}, s.created_at DESC",
            'status' => "FIELD(s.status, 'active', 'inactive', 'blocked') {$direction}, s.created_at DESC",
            default => "s.created_at {$direction}, s.id ASC",
        };

        $sql = "SELECT
                    s.*,
                    c.name AS campaign_name,
                    COALESCE(cc.clicks, 0) AS clicks_count
                FROM shortcuts s
                LEFT JOIN campaigns c ON c.id = s.campaign_id AND c.is_deleted = 0
                LEFT JOIN (
                    SELECT shortcut_id, COUNT(*) AS clicks
                    FROM click_events
                    GROUP BY shortcut_id
                ) cc ON cc.shortcut_id = s.id
                WHERE {$whereSql}
                ORDER BY {$orderSql}
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return ['items' => $this->attachTags($rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id, string $userId): ?array
    {
        $sql = "SELECT
                    s.*,
                    c.name AS campaign_name,
                    (SELECT COUNT(*) FROM click_events ce WHERE ce.shortcut_id = s.id) AS clicks_count
                FROM shortcuts s
                LEFT JOIN campaigns c ON c.id = s.campaign_id AND c.is_deleted = 0
                WHERE s.id = :id AND s.user_id = :user_id AND s.is_deleted = 0
                LIMIT 1";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $this->attachTags([$row])[0];
    }

    /** @return array<string, mixed>|null */
    public function findActiveByCode(string $code): ?array
    {
        $sql = "SELECT
                    s.*,
                    c.utm_json AS campaign_utm_json,
                    c.is_deleted AS campaign_is_deleted
                FROM shortcuts s
                LEFT JOIN campaigns c ON c.id = s.campaign_id
                WHERE s.active_code = :code
                LIMIT 1";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data
     *  @param string[] $tags
     */
    public function create(array $data, array $tags = []): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO shortcuts (
                    id, user_id, campaign_id, code, title, description, target, reserve_url,
                    status, block_reason, source, compression, channel, utm_json, expires_at,
                    is_deleted, created_at, updated_at
                ) VALUES (
                    :id, :user_id, :campaign_id, :code, :title, :description, :target, :reserve_url,
                    :status, :block_reason, :source, :compression, :channel, :utm_json, :expires_at,
                    0, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':id' => $data['id'],
                ':user_id' => $data['user_id'],
                ':campaign_id' => $data['campaign_id'],
                ':code' => $data['code'],
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':target' => $data['target'],
                ':reserve_url' => $data['reserve_url'],
                ':status' => $data['status'],
                ':block_reason' => $data['block_reason'],
                ':source' => $data['source'],
                ':compression' => $data['compression'],
                ':channel' => $data['channel'],
                ':utm_json' => $data['utm_json'],
                ':expires_at' => $data['expires_at'],
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at'],
            ]);

            if ($tags !== []) {
                $this->replaceTags($pdo, (string) $data['id'], $tags);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param string[]|null $tags null — не изменять теги, array — заменить теги
     */
    public function update(string $id, string $userId, array $fields, ?array $tags, string $nowMysql): bool
    {
        $allowed = [
            'title', 'description', 'target', 'campaign_id', 'utm_json',
            'expires_at', 'channel', 'status', 'reserve_url', 'block_reason',
        ];
        $sets = [];
        $params = [':id' => $id, ':user_id' => $userId, ':updated_at' => $nowMysql];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT 1 FROM shortcuts WHERE id = :id AND user_id = :user_id AND is_deleted = 0 LIMIT 1');
            $check->execute([':id' => $id, ':user_id' => $userId]);
            if ($check->fetchColumn() === false) {
                $pdo->rollBack();
                return false;
            }

            if ($sets !== [] || $tags !== null) {
                $sets[] = 'updated_at = :updated_at';
                $sql = 'UPDATE shortcuts SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :user_id AND is_deleted = 0';
                $pdo->prepare($sql)->execute($params);
            }

            if ($tags !== null) {
                $this->replaceTags($pdo, $id, $tags);
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function softDelete(string $id, string $userId, string $nowMysql): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE shortcuts SET is_deleted = 1, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND is_deleted = 0'
        );
        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':updated_at' => $nowMysql,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Атомарное закрепление гостевой ссылки за авторизованным пользователем.
     *
     * @return array{status: string, row?: array<string, mixed>}
     */
    public function claimByCode(string $code, string $userId, string $nowMysql): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM shortcuts WHERE active_code = :code LIMIT 1 FOR UPDATE');
            $stmt->execute([':code' => $code]);
            $row = $stmt->fetch();

            if (!is_array($row)) {
                $pdo->rollBack();
                return ['status' => 'not_found'];
            }

            // Уже принадлежит текущему пользователю — идемпотентный успех
            if ($row['user_id'] === $userId) {
                $pdo->commit();
                return ['status' => 'ok', 'row' => $row];
            }

            if ($row['source'] !== 'public' || $row['user_id'] !== null) {
                $pdo->rollBack();
                return ['status' => 'already_claimed'];
            }

            if ($row['expires_at'] !== null && (string) $row['expires_at'] <= $nowMysql) {
                $pdo->rollBack();
                return ['status' => 'expired'];
            }

            $update = $pdo->prepare(
                "UPDATE shortcuts
                 SET user_id = :user_id, source = 'account', expires_at = NULL, updated_at = :updated_at
                 WHERE id = :id"
            );
            $update->execute([
                ':user_id' => $userId,
                ':updated_at' => $nowMysql,
                ':id' => $row['id'],
            ]);

            // Обновляем user_id в уже собранных кликах гостевой ссылки, чтобы они попали в статистику аккаунта
            $updateClicks = $pdo->prepare(
                'UPDATE click_events SET user_id = :user_id WHERE shortcut_id = :shortcut_id AND user_id IS NULL'
            );
            $updateClicks->execute([
                ':user_id' => $userId,
                ':shortcut_id' => $row['id'],
            ]);

            $pdo->commit();
            $row['user_id'] = $userId;
            $row['source'] = 'account';
            $row['expires_at'] = null;
            return ['status' => 'ok', 'row' => $row];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array{name: string, count: int}> */
    public function listTags(string $userId, ?string $campaignId = null, bool $showHidden = true): array
    {
        $where = ['s.user_id = :user_id', 's.is_deleted = 0'];
        $params = [':user_id' => $userId];

        if ($campaignId !== null) {
            $where[] = 's.campaign_id = :campaign_id';
            $params[':campaign_id'] = $campaignId;
        }
        if (!$showHidden) {
            $where[] = "s.status = 'active'";
        }

        $sql = 'SELECT st.tag AS name, COUNT(*) AS cnt
                FROM shortcut_tags st
                INNER JOIN shortcuts s ON s.id = st.shortcut_id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY st.tag
                ORDER BY cnt DESC, LOWER(st.tag) ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[] = [
                'name' => (string) $row['name'],
                'count' => (int) $row['cnt'],
            ];
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachTags(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT shortcut_id, tag FROM shortcut_tags WHERE shortcut_id IN ({$placeholders}) ORDER BY tag ASC"
        );
        $stmt->execute($ids);

        $byShortcut = [];
        foreach ($stmt->fetchAll() as $tagRow) {
            $byShortcut[(string) $tagRow['shortcut_id']][] = (string) $tagRow['tag'];
        }

        foreach ($rows as &$row) {
            $row['tags'] = $byShortcut[(string) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /** @param string[] $tags */
    private function replaceTags(PDO $pdo, string $shortcutId, array $tags): void
    {
        $del = $pdo->prepare('DELETE FROM shortcut_tags WHERE shortcut_id = :id');
        $del->execute([':id' => $shortcutId]);

        if ($tags === []) {
            return;
        }

        $ins = $pdo->prepare('INSERT INTO shortcut_tags (shortcut_id, tag) VALUES (:id, :tag)');
        foreach ($tags as $tag) {
            $ins->execute([':id' => $shortcutId, ':tag' => $tag]);
        }
    }
}
