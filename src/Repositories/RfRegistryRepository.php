<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class RfRegistryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Check if given host, full URL, or IP is in the banned registry.
     * @return array{matched: bool, type: string, value: string, comment: ?string}|null
     */
    public function checkBanned(string $host, string $url, ?string $ip = null): ?array
    {
        $pdo = $this->db->pdo();

        // 1. Check domain variants (exact host, and base domains e.g. sub.example.com -> example.com)
        $domainCandidates = self::buildDomainCandidates($host);
        if (!empty($domainCandidates)) {
            $inClause = implode(',', array_fill(0, count($domainCandidates), '?'));
            $stmt = $pdo->prepare(
                "SELECT entry_type, value, comment FROM rf_banned_registry 
                 WHERE entry_type = 'domain' AND value IN ($inClause) LIMIT 1"
            );
            $stmt->execute($domainCandidates);
            $row = $stmt->fetch();
            if ($row !== false) {
                return [
                    'matched' => true,
                    'type' => (string) $row['entry_type'],
                    'value' => (string) $row['value'],
                    'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
                ];
            }
        }

        // 2. Check exact URL or URL prefix
        $normalizedUrl = rtrim($url, '/');
        $stmt = $pdo->prepare(
            "SELECT entry_type, value, comment FROM rf_banned_registry 
             WHERE entry_type = 'url' AND (value = ? OR value = ?) LIMIT 1"
        );
        $stmt->execute([$normalizedUrl, $normalizedUrl . '/']);
        $row = $stmt->fetch();
        if ($row !== false) {
            return [
                'matched' => true,
                'type' => (string) $row['entry_type'],
                'value' => (string) $row['value'],
                'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
            ];
        }

        // 3. Check IP if provided
        if ($ip !== null && $ip !== '') {
            $stmt = $pdo->prepare(
                "SELECT entry_type, value, comment FROM rf_banned_registry 
                 WHERE entry_type = 'ip' AND value = ? LIMIT 1"
            );
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return [
                    'matched' => true,
                    'type' => (string) $row['entry_type'],
                    'value' => (string) $row['value'],
                    'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string> e.g. ['sub.domain.com', 'domain.com'] and wildcard variants
     */
    public static function buildDomainCandidates(string $host): array
    {
        $clean = strtolower(trim($host, '.'));
        if ($clean === '') {
            return [];
        }

        $candidates = [$clean, "*.$clean"];
        $parts = explode('.', $clean);
        while (count($parts) > 2) {
            array_shift($parts);
            $parent = implode('.', $parts);
            $candidates[] = $parent;
            $candidates[] = "*.$parent";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Batch insert entries (chunks of up to 500 rows).
     * @param list<array{type: string, value: string, comment: ?string, source: string, created_at: string}> $rows
     */
    public function batchInsert(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $pdo = $this->db->pdo();
        $totalInserted = 0;
        $chunks = array_chunk($rows, 500);

        foreach ($chunks as $chunk) {
            $valuePlaceholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $valuePlaceholders[] = '(?, ?, ?, ?, ?)';
                $params[] = $row['type'];
                $params[] = mb_substr($row['value'], 0, 1024);
                $params[] = $row['comment'] !== null ? mb_substr($row['comment'], 0, 500) : null;
                $params[] = $row['source'];
                $params[] = $row['created_at'];
            }

            $sql = 'INSERT INTO rf_banned_registry (entry_type, value, comment, source, created_at) VALUES '
                . implode(', ', $valuePlaceholders);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $totalInserted += count($chunk);
        }

        return $totalInserted;
    }

    public function truncateSyncSource(string $source = 'sync'): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM rf_banned_registry WHERE source = :source');
        $stmt->execute([':source' => $source]);
    }

    public function createManual(string $type, string $value, ?string $comment, string $nowMysql): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO rf_banned_registry (entry_type, value, comment, source, created_at)
             VALUES (:type, :value, :comment, :source, :created_at)'
        );
        $stmt->execute([
            ':type' => $type,
            ':value' => $value,
            ':comment' => $comment,
            ':source' => 'manual',
            ':created_at' => $nowMysql,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM rf_banned_registry WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{total: int, domains: int, urls: int, ips: int, lastSync: ?string}
     */
    public function getStats(): array
    {
        $pdo = $this->db->pdo();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM rf_banned_registry')->fetchColumn();
        
        $typeStmt = $pdo->query('SELECT entry_type, COUNT(*) as cnt FROM rf_banned_registry GROUP BY entry_type');
        $counts = ['domain' => 0, 'url' => 0, 'ip' => 0];
        if ($typeStmt !== false) {
            foreach ($typeStmt->fetchAll() as $r) {
                $counts[(string) $r['entry_type']] = (int) $r['cnt'];
            }
        }

        $lastSync = $pdo->query("SELECT MAX(created_at) FROM rf_banned_registry WHERE source = 'sync'")->fetchColumn();

        return [
            'total' => $total,
            'domains' => $counts['domain'],
            'urls' => $counts['url'],
            'ips' => $counts['ip'],
            'lastSync' => $lastSync ?: null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 50, ?string $query = null): array
    {
        $pdo = $this->db->pdo();
        if ($query !== null && trim($query) !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, entry_type, value, comment, source, created_at 
                 FROM rf_banned_registry 
                 WHERE value LIKE :q 
                 ORDER BY id DESC LIMIT :lim'
            );
            $stmt->bindValue(':q', '%' . trim($query) . '%');
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, entry_type, value, comment, source, created_at 
                 FROM rf_banned_registry 
                 ORDER BY id DESC LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt !== false ? $stmt->fetchAll() : [];
    }
}
