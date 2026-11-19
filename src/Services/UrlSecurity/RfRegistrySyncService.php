<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity;

use App\Config;
use App\Repositories\RfRegistryRepository;
use App\Services\DateHelper;

final class RfRegistrySyncService
{
    private const DEFAULT_MIRROR = 'https://raw.githubusercontent.com/zapret-info/z-i/master/dump.csv';

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
        $url = $customUrl ?: $this->config->get('RF_REGISTRY_MIRROR_URL', self::DEFAULT_MIRROR);

        // Allow increased memory and execution time for sync
        @ini_set('memory_limit', '256M');
        @set_time_limit(300);

        $timeout = $this->config->getInt('RF_REGISTRY_SYNC_TIMEOUT', 60);
        $download = HttpHelper::get($url, [], $timeout);

        if ($download['error'] !== null || $download['statusCode'] !== 200) {
            $duration = round(microtime(true) - $startTime, 3);
            return [
                'success' => false,
                'mirrorUrl' => $url,
                'downloadedBytes' => strlen($download['body']),
                'parsed' => 0,
                'inserted' => 0,
                'durationSeconds' => $duration,
                'error' => $download['error'] ?: "HTTP download status {$download['statusCode']}",
            ];
        }

        $content = $download['body'];
        // Detect Windows-1251 encoding often used in zapret-info dumps
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'CP1251');
            if ($converted !== false && is_string($converted)) {
                $content = $converted;
            }
        }

        $entries = $this->parseContent($content, $maxEntries);
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());

        // Replace previous synced entries
        $this->rfRepo->truncateSyncSource('sync');
        $inserted = $this->rfRepo->batchInsert($entries);

        $duration = round(microtime(true) - $startTime, 3);

        return [
            'success' => true,
            'mirrorUrl' => $url,
            'downloadedBytes' => strlen($content),
            'parsed' => count($entries),
            'inserted' => $inserted,
            'durationSeconds' => $duration,
            'error' => null,
        ];
    }

    /**
     * @return list<array{type: string, value: string, comment: ?string, source: string, created_at: string}>
     */
    private function parseContent(string $raw, int $maxEntries): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        $seen = [];
        $nowMysql = DateHelper::toMysqlUtc(DateHelper::nowUtc());

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (count($entries) >= $maxEntries) {
                break;
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
                        $d = strtolower(trim($d));
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
                        $u = trim($u);
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
                        $ip = trim($ip);
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
                // Plain list of domains or URLs or IPs
                $item = trim($line);
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

        return $entries;
    }
}
