#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Скрипт периодической очистки базы данных для запуска через cron хостинга.
 *
 * Примеры запуска:
 *   php bin/cleanup.php               # Стандартный запуск очистки
 *   php bin/cleanup.php --dry-run     # Тестовый прогон (без удаления)
 *   php bin/cleanup.php --quiet       # Без вывода в консоль (для cron без лишних писем)
 *   php bin/cleanup.php --json        # Вывод результата в формате JSON
 *
 * Пример настройки в crontab:
 *   0 * * * * /usr/bin/php /home/user/shortcut-php/bin/cleanup.php --quiet >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line (CLI).\n";
    exit(1);
}

// Автозагрузка
$autoloadCandidates = [
    __DIR__ . '/../src/autoload.php',
    dirname(__DIR__) . '/src/autoload.php',
    dirname(__DIR__, 2) . '/src/autoload.php',
    __DIR__ . '/src/autoload.php',
];

$loaded = false;
foreach ($autoloadCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Error: src/autoload.php not found.\n");
    exit(1);
}

use App\Config;
use App\Database;
use App\Repositories\ShortcutRepository;
use App\Repositories\UserRepository;
use App\Services\CleanupService;

$args = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$quiet = in_array('--quiet', $args, true) || in_array('-q', $args, true);
$asJson = in_array('--json', $args, true);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo <<<HELP
Очистка базы данных сервиса «Короткие ссылки» (shortcut-php)

Использование:
  php bin/cleanup.php [ПАРАМЕТРЫ]

Параметры:
  --dry-run       Тестовый запуск: только подсчет записей без их удаления
  --quiet, -q     Подавить текстовый вывод (рекомендуется для cron)
  --json          Вывести результат в формате JSON
  --help, -h      Показать эту справку

Что очищается:
  1. Анонимные ссылки старше 48 часов (PUBLIC_SHORTCUT_TTL_HOURS) и их переходы
  2. Неподтверждённые аккаунты пользователей старше 48 часов (UNVERIFIED_USER_TTL_HOURS)
  3. Истёкшие коды верификации email и токены сброса пароля
  4. Устаревшие записи rate limiting

Настройка на хостинге (раз в час):
  0 * * * * /usr/bin/php /путь/к/проекту/bin/cleanup.php --quiet

HELP;
    exit(0);
}

try {
    $startTime = microtime(true);
    $config = new Config();
    $db = new Database($config);
    $shortcutRepo = new ShortcutRepository($db);
    $userRepo = new UserRepository($db);
    $cleanupService = new CleanupService($db, $config, $shortcutRepo, $userRepo);

    $result = $cleanupService->run($dryRun);
    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    $result['duration_ms'] = $durationMs;

    if ($asJson) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    if (!$quiet) {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        echo sprintf(
            "%s[%s] Очистка базы данных успешно завершена за %s мс:\n" .
            "  - Анонимных ссылок удалено: %d\n" .
            "  - Связанных переходов (кликов) удалено: %d\n" .
            "  - Неподтверждённых пользователей удалено: %d\n" .
            "  - Просроченных токенов верификации email удалено: %d\n" .
            "  - Просроченных токенов сброса пароля удалено: %d\n" .
            "  - Устаревших записей rate limits удалено: %d\n",
            $prefix,
            $result['executed_at'] ?? date('c'),
            $durationMs,
            $result['anonymous_shortcuts_deleted'] ?? ($result['anonymous_shortcuts_to_delete'] ?? 0),
            $result['associated_clicks_deleted'] ?? 0,
            $result['unverified_users_deleted'] ?? ($result['unverified_users_to_delete'] ?? 0),
            $result['expired_verifications_deleted'] ?? ($result['expired_verifications_to_delete'] ?? 0),
            $result['expired_password_resets_deleted'] ?? ($result['expired_password_resets_to_delete'] ?? 0),
            $result['expired_rate_limits_deleted'] ?? ($result['expired_rate_limits_to_delete'] ?? 0)
        );
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Ошибка при очистке БД: %s\n%s\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getTraceAsString()));
    exit(1);
}
