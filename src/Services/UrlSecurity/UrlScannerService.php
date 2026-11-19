<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity;

use App\Database;
use App\Services\DateHelper;

final class UrlScannerService
{
    public function __construct(
        private readonly Database $db,
        private readonly UrlSecurityManager $securityManager,
    ) {
    }

    /**
     * @return array{
     *     totalScanned: int,
     *     safe: int,
     *     suspicious: int,
     *     blocked: int,
     *     errors: int,
     *     blockedItems: list<array{id: string, code: string, target: string, reason: string}>
     * }
     */
    public function scanActiveShortcuts(int $limit = 500): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, code, target, reserve_url FROM shortcuts 
             WHERE status = 'active' AND is_deleted = 0 
             ORDER BY created_at DESC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $scanned = 0;
        $safeCount = 0;
        $suspiciousCount = 0;
        $blockedCount = 0;
        $errorCount = 0;
        $blockedItems = [];
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());

        $updateStmt = $pdo->prepare(
            "UPDATE shortcuts 
             SET status = 'blocked', block_reason = :reason, updated_at = :now 
             WHERE id = :id"
        );

        foreach ($rows as $row) {
            $scanned++;
            $id = (string) $row['id'];
            $code = (string) $row['code'];
            $target = (string) $row['target'];
            $reserve = !empty($row['reserve_url']) ? (string) $row['reserve_url'] : null;

            try {
                $targetResult = $this->securityManager->check($target, false);
                $isTargetBlocked = !empty($targetResult['isBlocked']) || !empty($targetResult['isInvalid']);
                $reason = $targetResult['error'] ?? ($targetResult['reasons'][0] ?? 'Заблокировано при фоновом сканировании');

                $isReserveBlocked = false;
                if (!$isTargetBlocked && $reserve !== null) {
                    $reserveResult = $this->securityManager->check($reserve, false);
                    $isReserveBlocked = !empty($reserveResult['isBlocked']) || !empty($reserveResult['isInvalid']);
                    if ($isReserveBlocked) {
                        $reason = 'Резервный URL заблокирован: ' . ($reserveResult['error'] ?? 'угроза безопасности');
                    }
                }

                if ($isTargetBlocked || $isReserveBlocked) {
                    $updateStmt->execute([
                        ':reason' => mb_substr($reason, 0, 500),
                        ':now' => $nowMysql,
                        ':id' => $id,
                    ]);
                    $blockedCount++;
                    $blockedItems[] = [
                        'id' => $id,
                        'code' => $code,
                        'target' => $target,
                        'reason' => $reason,
                    ];
                } elseif (!empty($targetResult['isSuspicious'])) {
                    $suspiciousCount++;
                } else {
                    $safeCount++;
                }
            } catch (\Throwable $e) {
                $errorCount++;
            }
        }

        return [
            'totalScanned' => $scanned,
            'safe' => $safeCount,
            'suspicious' => $suspiciousCount,
            'blocked' => $blockedCount,
            'errors' => $errorCount,
            'blockedItems' => $blockedItems,
        ];
    }
}
