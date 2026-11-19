#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Скрипт синхронизации безопасности ссылок для cron хостинга.
 *
 * Примеры запуска:
 *   php bin/security_sync.php                 # Полная синхронизация (реестр РФ, кэш, сканирование)
 *   php bin/security_sync.php --sync-rf       # Только обновление реестра РФ
 *   php bin/security_sync.php --prune-cache   # Только очистка устаревшего кэша
 *   php bin/security_sync.php --scan-links    # Только фоновое сканирование существующих ссылок
 *   php bin/security_sync.php --quiet         # Без вывода (для cron)
 *   php bin/security_sync.php --json          # Вывод отчёта в формате JSON
 *
 * Пример в crontab (каждый день в 03:00):
 *   0 3 * * * /usr/bin/php /home/user/shortcut-php/bin/security_sync.php --quiet >/dev/null 2>&1
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
use App\Repositories\BlacklistRepository;
use App\Repositories\RfRegistryRepository;
use App\Repositories\UrlCheckCacheRepository;
use App\Repositories\WhitelistRepository;
use App\Services\DateHelper;
use App\Services\UrlSecurity\Providers\GoogleSafeBrowsingProvider;
use App\Services\UrlSecurity\Providers\UrlhausProvider;
use App\Services\UrlSecurity\Providers\VirusTotalProvider;
use App\Services\UrlSecurity\RfRegistrySyncService;
use App\Services\UrlSecurity\UrlScannerService;
use App\Services\UrlSecurity\UrlSecurityManager;
use App\Services\UrlTools;

$args = $argv ?? [];
$quiet = in_array('--quiet', $args, true) || in_array('-q', $args, true);
$asJson = in_array('--json', $args, true);

$onlySyncRf = in_array('--sync-rf', $args, true);
$onlyPruneCache = in_array('--prune-cache', $args, true);
$onlyScanLinks = in_array('--scan-links', $args, true);

$runAll = !$onlySyncRf && !$onlyPruneCache && !$onlyScanLinks;

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo <<<HELP
Служба безопасности ссылок shortcut-php (Security Sync & Audit)

Использование:
  php bin/security_sync.php [ПАРАМЕТРЫ]

Параметры:
  --sync-rf       Обновить реестр запрещённых ресурсов РФ из открытого зеркала
  --prune-cache   Удалить истёкшие записи из кэша проверок (url_check_cache)
  --scan-links    Фоновая проверка существующих активных ссылок с блокировкой вредоносных
  --quiet, -q     Подавить текстовый вывод (рекомендуется для cron)
  --json          Вывести результат в формате JSON
  --help, -h      Показать эту справку

По умолчанию выполняются все три действия.

HELP;
    exit(0);
}

try {
    $startTime = microtime(true);
    $config = new Config();
    $db = new Database($config);
    $urlTools = new UrlTools($config);

    $blacklistRepo = new BlacklistRepository($db);
    $whitelistRepo = new WhitelistRepository($db);
    $rfRepo = new RfRegistryRepository($db);
    $cacheRepo = new UrlCheckCacheRepository($db);

    $securityManager = new UrlSecurityManager(
        $config,
        $urlTools,
        $blacklistRepo,
        $whitelistRepo,
        $rfRepo,
        $cacheRepo
    );
    $securityManager->addProvider(new GoogleSafeBrowsingProvider($config));
    $securityManager->addProvider(new VirusTotalProvider($config));
    $securityManager->addProvider(new UrlhausProvider($config));

    $rfSyncService = new RfRegistrySyncService($config, $rfRepo);
    $scannerService = new UrlScannerService($db, $securityManager);

    $report = [
        'timestamp' => DateHelper::nowUtc()->format('Y-m-d\TH:i:s\Z'),
        'rfRegistrySync' => null,
        'cachePruned' => null,
        'linkScan' => null,
    ];

    // 1. Sync RF Registry
    if ($runAll || $onlySyncRf) {
        if ($config->getBool('RF_REGISTRY_SYNC_ENABLED', true)) {
            $report['rfRegistrySync'] = $rfSyncService->sync();
        } else {
            $report['rfRegistrySync'] = ['skipped' => true, 'reason' => 'RF_REGISTRY_SYNC_ENABLED is false'];
        }
    }

    // 2. Prune expired cache
    if ($runAll || $onlyPruneCache) {
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());
        $deletedCache = $cacheRepo->pruneExpired($nowMysql);
        $report['cachePruned'] = ['deletedEntries' => $deletedCache];
    }

    // 3. Scan active links
    if ($runAll || $onlyScanLinks) {
        $report['linkScan'] = $scannerService->scanActiveShortcuts();
    }

    $duration = round(microtime(true) - $startTime, 3);
    $report['durationSeconds'] = $duration;

    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    if (!$quiet) {
        echo "=========================================================\n";
        echo " Синхронизация безопасности ссылок завершена за {$duration} сек.\n";
        echo "=========================================================\n";

        if ($report['rfRegistrySync'] !== null) {
            $rf = $report['rfRegistrySync'];
            if (!empty($rf['success'])) {
                echo " [Реестр РФ] Успешно загружено {$rf['downloadedBytes']} байт, вставлено {$rf['inserted']} записей.\n";
            } elseif (!empty($rf['skipped'])) {
                echo " [Реестр РФ] Синхронизация пропущена ({$rf['reason']}).\n";
            } else {
                echo " [Реестр РФ] Ошибка загрузки: " . ($rf['error'] ?? 'Неизвестная ошибка') . "\n";
            }
        }

        if ($report['cachePruned'] !== null) {
            echo " [Кэш проверок] Удалено {$report['cachePruned']['deletedEntries']} устаревших записей.\n";
        }

        if ($report['linkScan'] !== null) {
            $ls = $report['linkScan'];
            echo " [Аудит ссылок] Просканировано: {$ls['totalScanned']}, безопасно: {$ls['safe']}, подозрительно: {$ls['suspicious']}, заблокировано: {$ls['blocked']}.\n";
            if (!empty($ls['blockedItems'])) {
                foreach ($ls['blockedItems'] as $bi) {
                    echo "   - Заблокирована ссылка [{$bi['code']}]: {$bi['target']} ({$bi['reason']})\n";
                }
            }
        }
        echo "=========================================================\n";
    }

    exit(0);
} catch (Throwable $e) {
    if ($asJson) {
        echo json_encode([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } elseif (!$quiet) {
        fwrite(STDERR, "Security Sync Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n");
    }
    exit(1);
}
