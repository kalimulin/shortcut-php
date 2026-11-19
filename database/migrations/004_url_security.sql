-- Миграция 004: подсистема расширенной безопасности ссылок (Whitelist, Реестр РФ, Кэш проверок)
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1. Белый список доменов и масок
CREATE TABLE IF NOT EXISTS whitelist (
    id         CHAR(36)     NOT NULL PRIMARY KEY,
    pattern    VARCHAR(255) NOT NULL,
    reason     VARCHAR(500) NULL,
    created_by CHAR(36)     NULL,
    created_at DATETIME     NOT NULL,
    UNIQUE KEY uq_whitelist_pattern (pattern),
    CONSTRAINT fk_whitelist_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Реестр ресурсов, запрещённых в РФ
CREATE TABLE IF NOT EXISTS rf_banned_registry (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_type  ENUM('domain', 'url', 'ip') NOT NULL DEFAULT 'domain',
    value       VARCHAR(1024)   NOT NULL,
    comment     VARCHAR(500)    NULL,
    source      VARCHAR(64)     NOT NULL DEFAULT 'sync',
    created_at  DATETIME        NOT NULL,
    INDEX idx_rf_banned_val (value(191)),
    INDEX idx_rf_banned_type (entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Кэш результатов внешних и комплексных проверок
CREATE TABLE IF NOT EXISTS url_check_cache (
    url_hash     CHAR(64)      NOT NULL PRIMARY KEY,
    url          VARCHAR(2048) NOT NULL,
    threat_level ENUM('safe', 'suspicious', 'blocked', 'invalid') NOT NULL,
    score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    details_json JSON          NOT NULL,
    expires_at   DATETIME      NOT NULL,
    created_at   DATETIME      NOT NULL,
    INDEX idx_url_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
