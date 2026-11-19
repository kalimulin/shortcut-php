<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Repositories\ShortcutRepository;
use App\Repositories\UserRepository;
use DateInterval;

final class CleanupService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?ShortcutRepository $shortcutRepo = null,
        private readonly ?UserRepository $userRepo = null,
    ) {
    }

    /**
     * Выполняет периодическую очистку базы данных:
     * 1. Удаление анонимных ссылок старше 48 часов (и связанных кликов/тегов)
     * 2. Удаление неподтверждённых пользователей старше 48 часов (и связанных данных)
     * 3. Удаление просроченных кодов верификации email и токенов сброса пароля
     * 4. Удаление устаревших записей rate limits
     *
     * @param bool $dryRun Если true, изменения в БД не вносятся, возвращается статистика кандидатов
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false): array
    {
        $now = DateHelper::nowUtc();
        $nowMysql = DateHelper::toMysqlUtc($now);

        $publicTtlHours = max(1, $this->config->getInt('PUBLIC_SHORTCUT_TTL_HOURS', 48));
        $unverifiedTtlHours = max(1, $this->config->getInt('UNVERIFIED_USER_TTL_HOURS', 48));

        $publicThreshold = $now->sub(new DateInterval("PT{$publicTtlHours}H"));
        $publicThresholdMysql = DateHelper::toMysqlUtc($publicThreshold);

        $unverifiedThreshold = $now->sub(new DateInterval("PT{$unverifiedTtlHours}H"));
        $unverifiedThresholdMysql = DateHelper::toMysqlUtc($unverifiedThreshold);

        $shortcutRepo = $this->shortcutRepo ?? new ShortcutRepository($this->db);
        $userRepo = $this->userRepo ?? new UserRepository($this->db);

        if ($dryRun) {
            $anonymousShortcutsCount = $shortcutRepo->countExpiredAnonymous($publicThresholdMysql, $nowMysql);
            $unverifiedUsersCount = $userRepo->countUnverifiedOlderThan($unverifiedThresholdMysql);
            $expiredTokens = $this->countExpiredTokens($nowMysql);

            return [
                'dry_run' => true,
                'anonymous_shortcuts_to_delete' => $anonymousShortcutsCount,
                'unverified_users_to_delete' => $unverifiedUsersCount,
                'expired_verifications_to_delete' => $expiredTokens['verifications'],
                'expired_password_resets_to_delete' => $expiredTokens['password_resets'],
                'expired_rate_limits_to_delete' => $expiredTokens['rate_limits'],
                'public_ttl_hours' => $publicTtlHours,
                'unverified_user_ttl_hours' => $unverifiedTtlHours,
                'executed_at' => $now->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        // 1. Очистка анонимных/устаревших ссылок (пачками по 200, до 50 итераций = до 10 000 за прогон)
        $totalDeletedShortcuts = 0;
        $totalDeletedClicks = 0;
        $maxShortcutBatches = 50;

        for ($i = 0; $i < $maxShortcutBatches; $i++) {
            $batchResult = $shortcutRepo->cleanupExpiredAnonymous($publicThresholdMysql, $nowMysql, 200);
            $totalDeletedShortcuts += $batchResult['deletedShortcuts'];
            $totalDeletedClicks += $batchResult['deletedClicks'];

            if ($batchResult['deletedShortcuts'] < 200) {
                break;
            }
        }

        // 2. Очистка неверифицированных пользователей (пачками по 100, до 50 итераций)
        $totalDeletedUsers = 0;
        $maxUserBatches = 50;

        for ($i = 0; $i < $maxUserBatches; $i++) {
            $batchResult = $userRepo->cleanupUnverified($unverifiedThresholdMysql, 100);
            $totalDeletedUsers += $batchResult['deletedUsers'];
            $totalDeletedShortcuts += $batchResult['deletedShortcuts'];
            $totalDeletedClicks += $batchResult['deletedClicks'];

            if ($batchResult['deletedUsers'] < 100) {
                break;
            }
        }

        // 3. Очистка просроченных токенов и записей rate limits
        $tokensResult = $this->cleanupExpiredTokens($nowMysql);

        return [
            'dry_run' => false,
            'anonymous_shortcuts_deleted' => $totalDeletedShortcuts,
            'associated_clicks_deleted' => $totalDeletedClicks,
            'unverified_users_deleted' => $totalDeletedUsers,
            'expired_verifications_deleted' => $tokensResult['deletedVerifications'],
            'expired_password_resets_deleted' => $tokensResult['deletedResets'],
            'expired_rate_limits_deleted' => $tokensResult['deletedRateLimits'],
            'public_ttl_hours' => $publicTtlHours,
            'unverified_user_ttl_hours' => $unverifiedTtlHours,
            'executed_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array{deletedVerifications: int, deletedResets: int, deletedRateLimits: int}
     */
    private function cleanupExpiredTokens(string $nowMysql): array
    {
        $pdo = $this->db->pdo();

        $delVerif = $pdo->prepare('DELETE FROM email_verifications WHERE expires_at <= :now');
        $delVerif->execute([':now' => $nowMysql]);
        $deletedVerif = $delVerif->rowCount();

        $delReset = $pdo->prepare('DELETE FROM password_resets WHERE expires_at <= :now');
        $delReset->execute([':now' => $nowMysql]);
        $deletedReset = $delReset->rowCount();

        $delLimits = $pdo->prepare('DELETE FROM rate_limits WHERE reset_at <= :nowTs');
        $delLimits->execute([':nowTs' => time()]);
        $deletedLimits = $delLimits->rowCount();

        return [
            'deletedVerifications' => $deletedVerif,
            'deletedResets' => $deletedReset,
            'deletedRateLimits' => $deletedLimits,
        ];
    }

    /**
     * @return array{verifications: int, password_resets: int, rate_limits: int}
     */
    private function countExpiredTokens(string $nowMysql): array
    {
        $pdo = $this->db->pdo();

        $vStmt = $pdo->prepare('SELECT COUNT(*) FROM email_verifications WHERE expires_at <= :now');
        $vStmt->execute([':now' => $nowMysql]);
        $verifications = (int) $vStmt->fetchColumn();

        $rStmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE expires_at <= :now');
        $rStmt->execute([':now' => $nowMysql]);
        $resets = (int) $rStmt->fetchColumn();

        $lStmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE reset_at <= :nowTs');
        $lStmt->execute([':nowTs' => time()]);
        $limits = (int) $lStmt->fetchColumn();

        return [
            'verifications' => $verifications,
            'password_resets' => $resets,
            'rate_limits' => $limits,
        ];
    }
}
