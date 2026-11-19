<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class UrlCheckCacheRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', strtolower(trim($url)));
    }

    /**
     * @return array{url: string, threat_level: string, score: int, details: array<string, mixed>, expires_at: string}|null
     */
    public function getValid(string $urlHash, string $nowMysql): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT url, threat_level, score, details_json, expires_at 
             FROM url_check_cache 
             WHERE url_hash = :hash AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            ':hash' => $urlHash,
            ':now' => $nowMysql,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $details = [];
        if (!empty($row['details_json'])) {
            $decoded = json_decode((string) $row['details_json'], true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        return [
            'url' => (string) $row['url'],
            'threat_level' => (string) $row['threat_level'],
            'score' => (int) $row['score'],
            'details' => $details,
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    public function set(
        string $urlHash,
        string $url,
        string $threatLevel,
        int $score,
        array $details,
        string $expiresAtMysql,
        string $nowMysql
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'REPLACE INTO url_check_cache (url_hash, url, threat_level, score, details_json, expires_at, created_at)
             VALUES (:hash, :url, :threat, :score, :details, :expires, :now)'
        );
        $stmt->execute([
            ':hash' => $urlHash,
            ':url' => mb_substr($url, 0, 2048),
            ':threat' => $threatLevel,
            ':score' => $score,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':expires' => $expiresAtMysql,
            ':now' => $nowMysql,
        ]);
    }

    public function pruneExpired(string $nowMysql): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM url_check_cache WHERE expires_at <= :now');
        $stmt->execute([':now' => $nowMysql]);
        return $stmt->rowCount();
    }
}
