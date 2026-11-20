-- Миграция 005: тарифные планы и индивидуальные лимиты пользователей
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE users
    ADD COLUMN plan ENUM('free', 'standard', 'pro', 'enterprise', 'custom') NOT NULL DEFAULT 'free' AFTER role,
    ADD COLUMN max_shortcuts INT UNSIGNED NULL DEFAULT NULL AFTER plan,
    ADD COLUMN max_campaigns INT UNSIGNED NULL DEFAULT NULL AFTER max_shortcuts;
