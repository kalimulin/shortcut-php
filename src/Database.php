<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config->get('DB_HOST', '127.0.0.1');
        $port = $this->config->get('DB_PORT', '3306');
        $name = $this->config->get('DB_NAME', 'shortcut_db');
        $user = $this->config->get('DB_USER', 'root');
        $pass = $this->config->get('DB_PASS', '');
        $charset = $this->config->get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");

            // Автоматическое накатывание недостающих таблиц и колонок
            $this->autoMigrate($pdo);

            $this->pdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException('Ошибка подключения к базе данных: ' . $e->getMessage(), 0, $e);
        }
    }

    private function autoMigrate(PDO $pdo): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;

        // 1. Проверяем, существует ли таблица users; если нет, накатываем полный 001_init.sql
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
            $hasUsers = $stmt ? (bool) $stmt->fetch() : false;

            if (!$hasUsers) {
                $candidates = [
                    dirname(__DIR__) . '/database/migrations/001_init.sql',
                    dirname(__DIR__, 2) . '/database/migrations/001_init.sql',
                    dirname(__DIR__) . '/001_init.sql',
                    __DIR__ . '/001_init.sql',
                ];
                foreach ($candidates as $file) {
                    if (is_file($file)) {
                        $sql = file_get_contents($file);
                        if ($sql !== false && $sql !== '') {
                            $pdo->exec($sql);
                            break;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Игнорируем
        }

        // 2. Проверяем наличие колонки is_verified в таблице users
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_verified'");
            if ($stmt && $stmt->fetch() === false) {
                $pdo->exec("ALTER TABLE users 
                    ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
                    ADD COLUMN verified_at DATETIME NULL AFTER is_verified");
            }
        } catch (Throwable) {
            // Игнорируем
        }

        // 3. Создаём таблицу email_verifications, если её нет
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
                id         CHAR(36)     NOT NULL PRIMARY KEY,
                user_id    CHAR(36)     NOT NULL,
                email      VARCHAR(255) NOT NULL,
                code       VARCHAR(16)  NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                attempts   INT          NOT NULL DEFAULT 0,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL,
                INDEX idx_email_verif_lookup (email, code),
                INDEX idx_email_verif_token (token),
                INDEX idx_email_verif_expires (expires_at),
                CONSTRAINT fk_email_verif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable) {
            // Игнорируем
        }

        // 4. Создаём таблицу password_resets, если её нет
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                id         CHAR(36)     NOT NULL PRIMARY KEY,
                user_id    CHAR(36)     NOT NULL,
                email      VARCHAR(255) NOT NULL,
                code       VARCHAR(16)  NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                attempts   INT          NOT NULL DEFAULT 0,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL,
                INDEX idx_pwd_reset_lookup (email, code),
                INDEX idx_pwd_reset_token (token),
                INDEX idx_pwd_reset_expires (expires_at),
                CONSTRAINT fk_pwd_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable) {
            // Игнорируем
        }

        // 5. Создаём таблицы расширенной безопасности ссылок (whitelist, rf_banned_registry, url_check_cache)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS whitelist (
                id         CHAR(36)     NOT NULL PRIMARY KEY,
                pattern    VARCHAR(255) NOT NULL,
                reason     VARCHAR(500) NULL,
                created_by CHAR(36)     NULL,
                created_at DATETIME     NOT NULL,
                UNIQUE KEY uq_whitelist_pattern (pattern)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS rf_banned_registry (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_type  ENUM('domain', 'url', 'ip') NOT NULL DEFAULT 'domain',
                value       VARCHAR(1024)   NOT NULL,
                comment     VARCHAR(500)    NULL,
                source      VARCHAR(64)     NOT NULL DEFAULT 'sync',
                created_at  DATETIME        NOT NULL,
                INDEX idx_rf_banned_val (value(191)),
                INDEX idx_rf_banned_type (entry_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS url_check_cache (
                url_hash     CHAR(64)      NOT NULL PRIMARY KEY,
                url          VARCHAR(2048) NOT NULL,
                threat_level ENUM('safe', 'suspicious', 'blocked', 'invalid') NOT NULL,
                score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                details_json JSON          NOT NULL,
                expires_at   DATETIME      NOT NULL,
                created_at   DATETIME      NOT NULL,
                INDEX idx_url_cache_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable) {
            // Игнорируем
        }
    }
}
