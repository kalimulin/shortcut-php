-- Миграция 002: верификация email и защита от ботов
SET NAMES utf8mb4;

-- 1. Добавление флага верификации в таблицу пользователей (если еще нет)
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
    ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER is_verified;

-- 2. Таблица кодов и токенов подтверждения email
CREATE TABLE IF NOT EXISTS email_verifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
