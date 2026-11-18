-- Сидер 001: начальный администратор и пример записи чёрного списка.
-- ВНИМАНИЕ: смените пароль администратора сразу после первого входа!
-- Для генерации нового bcrypt-хеша выполните:
--   php -r "echo password_hash('ВашНовыйПароль', PASSWORD_BCRYPT) . PHP_EOL;"
-- Пароль по умолчанию ниже: password (стандартный тестовый bcrypt-хеш).

INSERT IGNORE INTO users (
    id, login, password_hash, role, first_name, last_name, created_at, updated_at
) VALUES (
    '00000000-0000-4000-8000-000000000001',
    'admin@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'Администратор',
    'Системы',
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
);

-- Примечание: хеш выше соответствует паролю 'password' (стандартный тестовый bcrypt-хеш).
-- После создания админа через регистрацию вы также можете выдать права запросом:
--   UPDATE users SET role = 'admin' WHERE login = 'your@email.com';

INSERT IGNORE INTO blacklist (id, domain, reason, created_by, created_at) VALUES
(
    '00000000-0000-4000-8000-000000000101',
    'malware.test',
    'Тестовый заблокированный домен',
    '00000000-0000-4000-8000-000000000001',
    UTC_TIMESTAMP()
);
