<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class BlacklistRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, domain, reason, created_by, created_at FROM blacklist ORDER BY created_at DESC'
        );
        return $stmt !== false ? $stmt->fetchAll() : [];
    }

    /** @return array<string, ?string> */
    public function getDomainReasonMap(): array
    {
        $stmt = $this->db->pdo()->query('SELECT domain, reason FROM blacklist');
        if ($stmt === false) {
            return [];
        }
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['domain']] = $row['reason'] !== null ? (string) $row['reason'] : null;
        }
        return $map;
    }

    public function existsByDomain(string $domain): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM blacklist WHERE domain = :domain LIMIT 1');
        $stmt->execute([':domain' => $domain]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(string $id, string $domain, ?string $reason, ?string $createdBy, string $nowMysql): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO blacklist (id, domain, reason, created_by, created_at)
             VALUES (:id, :domain, :reason, :created_by, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':domain' => $domain,
            ':reason' => $reason,
            ':created_by' => $createdBy,
            ':created_at' => $nowMysql,
        ]);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM blacklist WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
