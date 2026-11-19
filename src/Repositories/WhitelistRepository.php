<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class WhitelistRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, pattern, reason, created_by, created_at FROM whitelist ORDER BY created_at DESC'
        );
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return list<string> */
    public function getAllPatterns(): array
    {
        $stmt = $this->db->pdo()->query('SELECT pattern FROM whitelist');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    public function existsByPattern(string $pattern): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM whitelist WHERE pattern = :pattern LIMIT 1');
        $stmt->execute([':pattern' => $pattern]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(string $id, string $pattern, ?string $reason, ?string $createdBy, string $nowMysql): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO whitelist (id, pattern, reason, created_by, created_at)
             VALUES (:id, :pattern, :reason, :created_by, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':pattern' => $pattern,
            ':reason' => $reason,
            ':created_by' => $createdBy,
            ':created_at' => $nowMysql,
        ]);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM whitelist WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
