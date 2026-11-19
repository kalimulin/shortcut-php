<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE login = :login LIMIT 1');
        $stmt->execute([':login' => strtolower(trim($login))]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(
        string $id,
        string $login,
        string $passwordHash,
        string $role,
        string $nowMysql,
        int $isVerified = 0,
    ): void {
        $normalized = strtolower(trim($login));
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (id, login, password_hash, role, is_verified, contact_email, created_at, updated_at)
                 VALUES (:id, :login, :password_hash, :role, :is_verified, :contact_email, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':id' => $id,
                ':login' => $normalized,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':is_verified' => $isVerified,
                ':contact_email' => str_contains($normalized, '@') ? $normalized : null,
                ':created_at' => $nowMysql,
                ':updated_at' => $nowMysql,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'is_verified')) {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO users (id, login, password_hash, role, contact_email, created_at, updated_at)
                     VALUES (:id, :login, :password_hash, :role, :contact_email, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':id' => $id,
                    ':login' => $normalized,
                    ':password_hash' => $passwordHash,
                    ':role' => $role,
                    ':contact_email' => str_contains($normalized, '@') ? $normalized : null,
                    ':created_at' => $nowMysql,
                    ':updated_at' => $nowMysql,
                ]);
                return;
            }
            throw $e;
        }
    }

    public function markVerified(string $id, string $nowMysql): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET is_verified = 1, verified_at = :verified_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':verified_at' => $nowMysql,
            ':updated_at' => $nowMysql,
            ':id' => $id,
        ]);
    }

    public function updatePassword(string $id, string $passwordHash, string $nowMysql): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => $passwordHash,
            ':updated_at' => $nowMysql,
            ':id' => $id,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function updateProfile(string $id, array $fields, string $nowMysql): void
    {
        $allowed = ['first_name', 'last_name', 'phone', 'contact_email', 'position', 'settings_json'];
        $sets = [];
        $params = [':id' => $id, ':updated_at' => $nowMysql];

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
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    public function deleteAccount(string $userId, bool $keepShortcuts = false, ?string $nowMysql = null): void
    {
        $pdo = $this->db->pdo();
        $now = $nowMysql ?? date('Y-m-d H:i:s');

        if (!$keepShortcuts) {
            // Короткие ссылки по умолчанию перестают работать
            $stmt = $pdo->prepare('UPDATE shortcuts SET is_deleted = 1, updated_at = :now WHERE user_id = :uid');
            $stmt->execute([':now' => $now, ':uid' => $userId]);
        }

        // Удаляем пользователя (связанные кампании, верификации и сбросы паролей удалятся по каскаду)
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :uid');
        $stmt->execute([':uid' => $userId]);
    }

    /**
     * Поиск и удаление партии неподтверждённых пользователей старше порога (например, 48 часов после регистрации).
     * Удаляет пользователя и все связанные с ним данные: ссылки, клики, кампании, токены.
     *
     * @return array{deletedUsers: int, deletedShortcuts: int, deletedClicks: int}
     */
    public function cleanupUnverified(string $thresholdMysql, int $limit = 100): array
    {
        $pdo = $this->db->pdo();

        $sql = "SELECT id FROM users
                WHERE is_verified = 0 AND created_at <= :threshold
                LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':threshold', $thresholdMysql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<string> $userIds */
        $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (empty($userIds)) {
            return ['deletedUsers' => 0, 'deletedShortcuts' => 0, 'deletedClicks' => 0];
        }

        $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));

        $pdo->beginTransaction();
        try {
            // 1. Находим ссылки этих пользователей, чтобы удалить их и связанные клики (не допуская превращения в анонимные из-за ON DELETE SET NULL)
            $findShortcuts = $pdo->prepare("SELECT id FROM shortcuts WHERE user_id IN ({$userPlaceholders})");
            $findShortcuts->execute($userIds);
            /** @var list<string> $shortcutIds */
            $shortcutIds = $findShortcuts->fetchAll(\PDO::FETCH_COLUMN);

            $deletedShortcuts = 0;
            $deletedClicks = 0;

            if (!empty($shortcutIds)) {
                $scPlaceholders = implode(',', array_fill(0, count($shortcutIds), '?'));

                $delClicks = $pdo->prepare("DELETE FROM click_events WHERE shortcut_id IN ({$scPlaceholders})");
                $delClicks->execute($shortcutIds);
                $deletedClicks += $delClicks->rowCount();

                $delTags = $pdo->prepare("DELETE FROM shortcut_tags WHERE shortcut_id IN ({$scPlaceholders})");
                $delTags->execute($shortcutIds);

                $delShortcuts = $pdo->prepare("DELETE FROM shortcuts WHERE id IN ({$scPlaceholders})");
                $delShortcuts->execute($shortcutIds);
                $deletedShortcuts = $delShortcuts->rowCount();
            }

            // 2. Клики, где user_id указан напрямую
            $delUserClicks = $pdo->prepare("DELETE FROM click_events WHERE user_id IN ({$userPlaceholders})");
            $delUserClicks->execute($userIds);
            $deletedClicks += $delUserClicks->rowCount();

            // 3. Кампании, верификации и сбросы паролей имеют ON DELETE CASCADE в схеме MySQL,
            // но для гарантированной очистки на любых конфигурациях СУБД выполним явный DELETE:
            $pdo->prepare("DELETE FROM email_verifications WHERE user_id IN ({$userPlaceholders})")->execute($userIds);
            $pdo->prepare("DELETE FROM password_resets WHERE user_id IN ({$userPlaceholders})")->execute($userIds);
            $pdo->prepare("DELETE FROM campaigns WHERE user_id IN ({$userPlaceholders})")->execute($userIds);

            // 4. Удаляем самих пользователей
            $delUsers = $pdo->prepare("DELETE FROM users WHERE id IN ({$userPlaceholders})");
            $delUsers->execute($userIds);
            $deletedUsers = $delUsers->rowCount();

            $pdo->commit();
            return [
                'deletedUsers' => $deletedUsers,
                'deletedShortcuts' => $deletedShortcuts,
                'deletedClicks' => $deletedClicks,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Подсчёт количества неподтверждённых пользователей старше указанного порога.
     */
    public function countUnverifiedOlderThan(string $thresholdMysql): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM users WHERE is_verified = 0 AND created_at <= :threshold");
        $stmt->execute([':threshold' => $thresholdMysql]);
        return (int) $stmt->fetchColumn();
    }
}
