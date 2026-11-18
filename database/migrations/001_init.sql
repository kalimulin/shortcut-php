-- Миграция 001: начальная схема БД сервиса «Короткие ссылки» (MySQL 5.7+ / MariaDB 10.2+)
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1. Пользователи и профиль
CREATE TABLE IF NOT EXISTS users (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    login         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    first_name    VARCHAR(120) NULL,
    last_name     VARCHAR(120) NULL,
    phone         VARCHAR(64)  NULL,
    contact_email VARCHAR(255) NULL,
    position      VARCHAR(150) NULL,
    settings_json JSON         NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_users_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Кампании
CREATE TABLE IF NOT EXISTS campaigns (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    user_id       CHAR(36)     NOT NULL,
    name          VARCHAR(255) NOT NULL,
    description   TEXT         NULL,
    tags_json     JSON         NULL,
    utm_json      JSON         NULL,
    starts_at     DATETIME     NULL,
    ends_at       DATETIME     NULL,
    is_deleted    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    INDEX idx_campaigns_user (user_id, is_deleted, created_at),
    CONSTRAINT fk_campaigns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Короткие ссылки
CREATE TABLE IF NOT EXISTS shortcuts (
    id            CHAR(36)      NOT NULL PRIMARY KEY,
    user_id       CHAR(36)      NULL,
    campaign_id   CHAR(36)      NULL,
    code          VARCHAR(64)   NOT NULL,
    active_code   VARCHAR(64)   GENERATED ALWAYS AS (IF(is_deleted = 0, code, NULL)) STORED,
    title         VARCHAR(255)  NOT NULL,
    description   TEXT          NULL,
    target        VARCHAR(2048) NOT NULL,
    reserve_url   VARCHAR(2048) NULL,
    status        ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    block_reason  VARCHAR(500)  NULL,
    source        ENUM('account', 'public') NOT NULL DEFAULT 'account',
    compression   ENUM('full', 'plain') NOT NULL DEFAULT 'full',
    channel       VARCHAR(64)   NULL,
    utm_json      JSON          NULL,
    expires_at    DATETIME      NULL,
    is_deleted    TINYINT(1)    NOT NULL DEFAULT 0,
    created_at    DATETIME      NOT NULL,
    updated_at    DATETIME      NOT NULL,
    UNIQUE KEY uq_shortcuts_active_code (active_code),
    INDEX idx_shortcuts_user (user_id, is_deleted, status, created_at),
    INDEX idx_shortcuts_campaign (campaign_id, is_deleted),
    INDEX idx_shortcuts_expires (source, expires_at),
    CONSTRAINT fk_shortcuts_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL,
    CONSTRAINT fk_shortcuts_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Нормализованные теги ссылок
CREATE TABLE IF NOT EXISTS shortcut_tags (
    shortcut_id   CHAR(36)     NOT NULL,
    tag           VARCHAR(100) NOT NULL,
    PRIMARY KEY (shortcut_id, tag),
    INDEX idx_shortcut_tags_tag (tag),
    CONSTRAINT fk_shortcut_tags_shortcut FOREIGN KEY (shortcut_id) REFERENCES shortcuts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. События переходов (синхронная запись при редиректе 302)
CREATE TABLE IF NOT EXISTS click_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shortcut_id   CHAR(36)        NOT NULL,
    campaign_id   CHAR(36)        NULL,
    user_id       CHAR(36)        NULL,
    code          VARCHAR(64)     NOT NULL,
    visitor_id    CHAR(32)        NOT NULL,
    referer       VARCHAR(1024)   NULL,
    user_agent    VARCHAR(512)    NULL,
    clicked_at    DATETIME(3)     NOT NULL,
    INDEX idx_clicks_shortcut_time (shortcut_id, clicked_at),
    INDEX idx_clicks_campaign_time (campaign_id, clicked_at),
    INDEX idx_clicks_user_time     (user_id, clicked_at),
    INDEX idx_clicks_shortcut_vis  (shortcut_id, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Чёрный список доменов (замена cfg.basis.skroy.ru)
CREATE TABLE IF NOT EXISTS blacklist (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    domain        VARCHAR(255) NOT NULL,
    reason        VARCHAR(500) NULL,
    created_by    CHAR(36)     NULL,
    created_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_blacklist_domain (domain),
    CONSTRAINT fk_blacklist_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Таблица rate limiting (замена nginx limit_req)
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket_key    VARCHAR(128) NOT NULL PRIMARY KEY,
    hits          INT UNSIGNED NOT NULL DEFAULT 1,
    reset_at      INT UNSIGNED NOT NULL,
    INDEX idx_rate_limits_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
