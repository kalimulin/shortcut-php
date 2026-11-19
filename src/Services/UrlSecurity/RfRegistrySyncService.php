<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity;

use App\Config;
use App\Repositories\RfRegistryRepository;
use App\Services\DateHelper;

final class RfRegistrySyncService
{
    public const DEFAULT_MIRROR = 'https://raw.githubusercontent.com/zapret-info/z-i/master/dump-00.csv';

    private const FALLBACK_MIRRORS = [
        'https://raw.githubusercontent.com/zapret-info/z-i/master/dump-00.csv',
        'https://antifilter.download/list/domains.lst',
        'https://community.antifilter.download/list/domains.lst',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly RfRegistryRepository $rfRepo,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     mirrorUrl: string,
     *     downloadedBytes: int,
     *     parsed: int,
     *     inserted: int,
     *     durationSeconds: float,
     *     error: ?string
     * }
     */
    public function sync(?string $customUrl = null, int $maxEntries = 50000): array
    {
        $startTime = microtime(true);
        $configuredUrl = $customUrl ?: $this->config->get('RF_REGISTRY_MIRROR_URL', self::DEFAULT_MIRROR);

        // Build list of candidate mirrors with automatic fallbacks
        $candidateUrls = $this->buildCandidateUrls($configuredUrl);

        @ini_set('memory_limit', '256M');
        @set_time_limit(300);

        $timeout = $this->config->getInt('RF_REGISTRY_SYNC_TIMEOUT', 60);

        $downloadedFile = null;
        $activeMirror = $configuredUrl;
        $downloadedSize = 0;
        $lastError = null;

        foreach ($candidateUrls as $url) {
            $dlResult = $this->downloadToTemp($url, $timeout);
            if ($dlResult['statusCode'] === 200 && $dlResult['path'] !== null && $dlResult['size'] > 100) {
                $downloadedFile = $dlResult['path'];
                $activeMirror = $url;
                $downloadedSize = $dlResult['size'];
                $lastError = null;
                break;
            }

            $lastError = $dlResult['error'] ?: "HTTP download status {$dlResult['statusCode']}";
        }

        if ($downloadedFile === null) {
            $duration = round(microtime(true) - $startTime, 3);
            return [
                'success' => false,
                'mirrorUrl' => $activeMirror,
                'downloadedBytes' => $downloadedSize,
                'parsed' => 0,
                'inserted' => 0,
                'durationSeconds' => $duration,
                'error' => $lastError ?: 'Не удалось загрузить реестр ни с одного из зеркал',
            ];
        }

        try {
            $entries = $this->parseFile($downloadedFile, $maxEntries);
            $this->rfRepo->truncateSyncSource('sync');
            $inserted = $this->rfRepo->batchInsert($entries);

            $duration = round(microtime(true) - $startTime, 3);

            return [
                'success' => true,
                'mirrorUrl' => $activeMirror,
                'downloadedBytes' => $downloadedSize,
                'parsed' => count($entries),
                'inserted' => $inserted,
                'durationSeconds' => $duration,
                'error' => null,
            ];
        } finally {
            if (is_file($downloadedFile)) {
                @unlink($downloadedFile);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function buildCandidateUrls(string $url): array
    {
        $urls = [$url];

        // If URL requested legacy /dump.csv, insert /dump-00.csv right after it
        if (str_ends_with($url, '/dump.csv')) {
            $urls[] = substr($url, 0, -8) . 'dump-00.csv';
        }

        foreach (self::FALLBACK_MIRRORS as $fallback) {
            if (!in_array($fallback, $urls, true)) {
                $urls[] = $fallback;
            }
        }

        return $urls;
    }

    /**
     * Downloads URL into a temporary file using cURL stream (constant low memory usage).
     * @return array{statusCode: int, path: ?string, size: int, error: ?string}
     */
    private function downloadToTemp(string $url, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['statusCode' => 0, 'path' => null, 'size' => 0, 'error' => 'cURL extension is not installed'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'rf_reg_');
        if ($tmpFile === false) {
            return ['statusCode' => 0, 'path' => null, 'size' => 0, 'error' => 'Cannot create temp file'];
        }

        $fp = fopen($tmpFile, 'w+');
        if ($fp === false) {
            @unlink($tmpFile);
            return ['statusCode' => 0, 'path' => null, 'size' => 0, 'error' => 'Cannot open temp file for writing'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ShortcutSecurityChecker/1.0 (+https://омни.рус)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $success = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $size = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;

        if (!$success || $statusCode !== 200 || $size === 0) {
            @unlink($tmpFile);
            return [
                'statusCode' => $statusCode,
                'path' => null,
                'size' => $size,
                'error' => $curlError ?: "HTTP download status {$statusCode}",
            ];
        }

        return [
            'statusCode' => 200,
            'path' => $tmpFile,
            'size' => $size,
            'error' => null,
        ];
    }

    /**
     * Line-by-line stream parsing of the downloaded dump file.
     * @return list<array{type: string, value: string, comment: ?string, source: string, created_at: string}>
     */
    private function parseFile(string $filePath, int $maxEntries): array
    {
        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return [];
        }

        $entries = [];
        $seen = [];
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());

        while (($rawLine = fgets($fp)) !== false) {
            if (count($entries) >= $maxEntries) {
                break;
            }

            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'Updated:')) {
                continue;
            }

            // Convert Windows-1251 to UTF-8 if necessary
            if (!mb_check_encoding($line, 'UTF-8')) {
                $converted = @mb_convert_encoding($line, 'UTF-8', 'CP1251');
                if ($converted !== false && is_string($converted)) {
                    $line = $converted;
                }
            }

            // Check if line is CSV format (zapret-info: IP;domain;URL;decision;date;agency;num)
            if (str_contains($line, ';')) {
                $parts = explode(';', $line);
                $ips = isset($parts[0]) ? trim($parts[0]) : '';
                $domains = isset($parts[1]) ? trim($parts[1]) : '';
                $urls = isset($parts[2]) ? trim($parts[2]) : '';
                $agency = isset($parts[5]) ? trim($parts[5]) : null;

                if ($domains !== '') {
                    foreach (explode('|', $domains) as $d) {
                        $d = strtolower(trim($d, " \t\n\r\0\x0B\"'"));
                        if ($d !== '' && !isset($seen["d:{$d}"])) {
                            $seen["d:{$d}"] = true;
                            $entries[] = [
                                'type' => 'domain',
                                'value' => $d,
                                'comment' => $agency,
                                'source' => 'sync',
                                'created_at' => $nowMysql,
                            ];
                        }
                    }
                }

                if ($urls !== '') {
                    foreach (explode('|', $urls) as $u) {
                        $u = trim($u, " \t\n\r\0\x0B\"'");
                        if ($u !== '' && !isset($seen["u:{$u}"])) {
                            $seen["u:{$u}"] = true;
                            $entries[] = [
                                'type' => 'url',
                                'value' => $u,
                                'comment' => $agency,
                                'source' => 'sync',
                                'created_at' => $nowMysql,
                            ];
                        }
                    }
                }

                if ($ips !== '') {
                    foreach (explode('|', $ips) as $ip) {
                        $ip = trim($ip, " \t\n\r\0\x0B\"'");
                        if ($ip !== '' && !isset($seen["i:{$ip}"])) {
                            $seen["i:{$ip}"] = true;
                            $entries[] = [
                                'type' => 'ip',
                                'value' => $ip,
                                'comment' => $agency,
                                'source' => 'sync',
                                'created_at' => $nowMysql,
                            ];
                        }
                    }
                }
            } else {
                // Plain list of domains, URLs or IPs (e.g. antifilter domains.lst)
                $item = trim($line, " \t\n\r\0\x0B\"'");
                if ($item === '') {
                    continue;
                }

                if (filter_var($item, FILTER_VALIDATE_IP) !== false) {
                    if (!isset($seen["i:{$item}"])) {
                        $seen["i:{$item}"] = true;
                        $entries[] = [
                            'type' => 'ip',
                            'value' => $item,
                            'comment' => null,
                            'source' => 'sync',
                            'created_at' => $nowMysql,
                        ];
                    }
                } elseif (str_starts_with($item, 'http://') || str_starts_with($item, 'https://')) {
                    if (!isset($seen["u:{$item}"])) {
                        $seen["u:{$item}"] = true;
                        $entries[] = [
                            'type' => 'url',
                            'value' => $item,
                            'comment' => null,
                            'source' => 'sync',
                            'created_at' => $nowMysql,
                        ];
                    }
                } else {
                    $domain = strtolower($item);
                    if (!isset($seen["d:{$domain}"])) {
                        $seen["d:{$domain}"] = true;
                        $entries[] = [
                            'type' => 'domain',
                            'value' => $domain,
                            'comment' => null,
                            'source' => 'sync',
                            'created_at' => $nowMysql,
                        ];
                    }
                }
            }
        }

        fclose($fp);
        return $entries;
    }
}
