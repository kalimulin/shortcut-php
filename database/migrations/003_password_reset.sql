-- Миграция: таблица для сброса и восстановления паролей
CREATE TABLE IF NOT EXISTS password_resets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
