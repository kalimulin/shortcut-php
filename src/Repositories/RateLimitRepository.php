<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class RateLimitRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function hit(string $bucketKey, int $maxRequests, int $windowSeconds): bool
    {
        $pdo = $this->db->pdo();
        $now = time();
        $resetAt = $now + $windowSeconds;

        // Ленивая очистка устаревших записей (~2% запросов)
        if (random_int(1, 50) === 1) {
            $cleanup = $pdo->prepare('DELETE FROM rate_limits WHERE reset_at <= :now');
            $cleanup->execute([':now' => $now]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, hits, reset_at)
             VALUES (:key, 1, :reset1)
             ON DUPLICATE KEY UPDATE
               hits = IF(reset_at <= :now1, 1, hits + 1),
               reset_at = IF(reset_at <= :now2, :reset2, reset_at)'
        );
        $stmt->execute([
            ':key' => $bucketKey,
            ':reset1' => $resetAt,
            ':now1' => $now,
            ':now2' => $now,
            ':reset2' => $resetAt,
        ]);

        $check = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket_key = :key LIMIT 1');
        $check->execute([':key' => $bucketKey]);
        $hits = (int) $check->fetchColumn();

        return $hits <= $maxRequests;
    }
}
