<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity;

use App\Config;
use App\Http\ApiException;
use App\Repositories\BlacklistRepository;
use App\Repositories\RfRegistryRepository;
use App\Repositories\UrlCheckCacheRepository;
use App\Repositories\WhitelistRepository;
use App\Services\DateHelper;
use App\Services\UrlSecurity\Providers\SecurityProviderInterface;
use App\Services\UrlTools;

final class UrlSecurityManager
{
    public const CHECKED_BY = 'Комплексная система безопасности омни.рус';
    public const MSG_INVALID = 'Некорректный URL. Проверьте формат URL.';
    public const MSG_BLOCKED = 'Ссылка заблокирована. Перенаправление на данный ресурс запрещено соображениями безопасности.';
    public const MSG_RF_BANNED = 'Ссылка заблокирована: ресурс внесён в единый реестр запрещённых сайтов РФ.';

    private const DEFAULT_SUSPICIOUS = [
        'bit.ly', 'tinyurl.com', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly',
        'cutt.ly', 'clck.ru', 't.ly', 'rebrand.ly', 'shorturl.at', 'v.gd',
    ];

    /** @var list<SecurityProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly Config $config,
        private readonly UrlTools $urlTools,
        private readonly BlacklistRepository $blacklistRepo,
        private readonly WhitelistRepository $whitelistRepo,
        private readonly RfRegistryRepository $rfRepo,
        private readonly UrlCheckCacheRepository $cacheRepo,
    ) {
    }

    public function addProvider(SecurityProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Complete URL check pipeline.
     * @param bool $fastPathOnly If true, runs only local DB checks and cache lookup (no external HTTP calls).
     * @return array<string, mixed>
     */
    public function check(string $url, bool $fastPathOnly = false): array
    {
        $value = trim($url);
        if ($value === '' || UrlTools::hasControlChars($value)) {
            return $this->invalidResult($value);
        }

        $parsed = parse_url($value);
        if (!is_array($parsed)) {
            return $this->invalidResult($value);
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parsed['host'])) {
            return $this->invalidResult($value);
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return $this->invalidResult($value);
        }

        $rawHost = trim((string) $parsed['host'], '[]');
        $host = UrlTools::normalizeHost($rawHost);
        if ($host === '') {
            return $this->invalidResult($value);
        }

        // 1. Local checks: localhost & private/reserved IPs
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return $this->blockedResult($value, 'Нельзя сокращать ссылки на localhost', 'Укажите публичный адрес');
        }

        if (self::isForbiddenIp($rawHost)) {
            return $this->blockedResult($value, 'Нельзя сокращать ссылки на внутренние адреса', 'Укажите публичный адрес');
        }

        // 2. Prevent self-redirection loops
        $ownHost = $this->urlTools->shortHost();
        if ($ownHost !== '' && UrlTools::hostMatches($host, $ownHost)) {
            return $this->blockedResult($value, 'Нельзя сокращать короткие ссылки этого сервиса', 'Используйте исходный адрес');
        }

        // 3. Whitelist check (fast-pass)
        if ($this->isWhitelisted($host, $value)) {
            return $this->makeResult(
                $value,
                'safe',
                100,
                ['Домен или адрес находится в белом списке доверенных ресурсов'],
                [],
                ['whitelist' => ['status' => 'clean', 'matched' => true]],
                false
            );
        }

        // 4. Local Blacklist check
        $blacklist = $this->blacklistRepo->getDomainReasonMap();
        foreach ($blacklist as $domain => $reason) {
            if (UrlTools::hostMatches($host, $domain)) {
                return $this->blockedResult(
                    $value,
                    $reason ?: "Домен «{$domain}» находится в чёрном списке",
                    'Не переходите по ссылке, если не уверены в источнике',
                    self::MSG_BLOCKED,
                    ['blacklist' => ['status' => 'threat', 'domain' => $domain, 'reason' => $reason]]
                );
            }
        }

        // 5. RF Banned Registry check
        $resolvedIp = filter_var($rawHost, FILTER_VALIDATE_IP) !== false ? $rawHost : null;
        $rfMatch = $this->rfRepo->checkBanned($host, $value, $resolvedIp);
        if ($rfMatch !== null && $rfMatch['matched']) {
            $reasonDetail = self::MSG_RF_BANNED;
            if (!empty($rfMatch['comment'])) {
                $reasonDetail .= ' (Орган: ' . $rfMatch['comment'] . ')';
            }
            return $this->blockedResult(
                $value,
                $reasonDetail,
                'Ресурс заблокирован в РФ согласно законодательству',
                self::MSG_BLOCKED,
                ['rf_registry' => ['status' => 'threat', 'match' => $rfMatch]]
            );
        }

        // 6. Cache check
        $nowUtc = DateHelper::nowUtc();
        $nowMysql = DateHelper::toMysqlUtc($nowUtc);
        $urlHash = UrlCheckCacheRepository::hashUrl($value);
        $cached = $this->cacheRepo->getValid($urlHash, $nowMysql);

        if ($cached !== null) {
            return $this->makeResult(
                $value,
                $cached['threat_level'],
                $cached['score'],
                $cached['details']['reasons'] ?? ['Ссылка проверена по локальной базе безопасности'],
                $cached['details']['recommendations'] ?? [],
                $cached['details']['providers'] ?? [],
                true,
                $cached['details']['error'] ?? null
            );
        }

        // If redirect / fast-path mode, do not execute external HTTP requests
        if ($fastPathOnly) {
            return $this->makeResult(
                $value,
                'safe',
                100,
                ['Ссылка успешно прошла экспресс-проверку безопасности'],
                [],
                ['fastPath' => ['status' => 'clean']],
                false
            );
        }

        // 7. Query External Providers
        $providerResults = [];
        $reasons = [];
        $recommendations = [];
        $hasThreat = false;
        $isSuspicious = false;
        $blockError = null;

        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }

            try {
                $res = $provider->check($value, $host);
                $providerResults[$provider->getName()] = $res;

                if ($res['status'] === 'threat') {
                    $hasThreat = true;
                    $threatName = $res['threatType'] ?: 'вредоносный контент';
                    $reasons[] = "Угроза обнаружена сервисом {$provider->getName()}: {$threatName}";
                    $blockError = self::MSG_BLOCKED;
                } elseif ($res['status'] === 'suspicious') {
                    $isSuspicious = true;
                    $reasons[] = "Подозрительная активность отмечена сервисом {$provider->getName()}";
                }
            } catch (\Throwable $e) {
                // Graceful degradation: log error and continue with other providers
                $providerResults[$provider->getName()] = [
                    'provider' => $provider->getName(),
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        // 8. Check third-party shortener suspicious domains
        $suspiciousDomains = $this->config->getList('URL_CHECK_SUSPICIOUS_DOMAINS', self::DEFAULT_SUSPICIOUS);
        foreach ($suspiciousDomains as $domain) {
            $normalized = UrlTools::normalizeHost($domain);
            if ($normalized !== '' && UrlTools::hostMatches($host, $normalized)) {
                $isSuspicious = true;
                $reasons[] = "Адрес ведёт на сторонний сервис коротких ссылок ({$domain})";
                $recommendations[] = 'Рекомендуется сокращать прямой адрес, а не чужую короткую ссылку';
                break;
            }
        }

        // 9. Aggregate verdict
        if ($hasThreat) {
            $level = 'blocked';
            $score = 0;
            $recommendations[] = 'Не переходите по ссылке, сайт может быть опасен для вашего устройства';
        } elseif ($isSuspicious) {
            $level = 'suspicious';
            $score = 50;
            if (empty($recommendations)) {
                $recommendations[] = 'Будьте внимательны при переходе на непроверенный ресурс';
            }
        } else {
            $level = 'safe';
            $score = 100;
            $reasons[] = 'Ссылка успешно прошла комплексную проверку безопасности';
        }

        $result = $this->makeResult(
            $value,
            $level,
            $score,
            $reasons,
            $recommendations,
            $providerResults,
            false,
            $blockError
        );

        // 10. Save in cache
        $ttlSeconds = $this->config->getInt('URL_CACHE_TTL_SECONDS', 86400);
        $expiresAtUtc = (clone $nowUtc)->modify("+{$ttlSeconds} seconds");
        $expiresAtMysql = DateHelper::toMysqlUtc($expiresAtUtc);

        $this->cacheRepo->set(
            $urlHash,
            $value,
            $level,
            $score,
            [
                'reasons' => $reasons,
                'recommendations' => $recommendations,
                'providers' => $providerResults,
                'error' => $blockError,
            ],
            $expiresAtMysql,
            $nowMysql
        );

        return $result;
    }

    /**
     * Throws ApiException if URL is invalid or blocked.
     * @return array<string, mixed>
     */
    public function ensureAllowed(string $url, bool $fastPathOnly = false): array
    {
        $result = $this->check($url, $fastPathOnly);
        if (!empty($result['isInvalid'])) {
            throw ApiException::badRequest('SHORTCUT_URL_INVALID', self::MSG_INVALID);
        }
        if (!empty($result['isBlocked'])) {
            $reason = $result['error'] ?? ($result['reasons'][0] ?? self::MSG_BLOCKED);
            throw ApiException::unprocessable('SHORTCUT_URL_BLOCKED', (string) $reason);
        }
        return $result;
    }

    private function isWhitelisted(string $host, string $url): bool
    {
        $patterns = $this->whitelistRepo->getAllPatterns();
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            // Pattern starts with *. (wildcard subdomain)
            if (str_starts_with($pattern, '*.')) {
                $base = substr($pattern, 2);
                if (UrlTools::hostMatches($host, $base)) {
                    return true;
                }
            }

            // Exact host or URL prefix
            if (UrlTools::hostMatches($host, $pattern) || str_starts_with($url, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function isForbiddenIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (!$isPublic) {
            return true;
        }

        $packed = @inet_pton($host);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 4) {
            $firstOctet = ord($packed[0]);
            if ($firstOctet >= 224 || $firstOctet === 0 || $firstOctet === 127) {
                return true;
            }
        } elseif (strlen($packed) === 16) {
            $firstByte = ord($packed[0]);
            if ($firstByte === 0xff || ($firstByte & 0xfe) === 0xfc || ($firstByte === 0xfe && (ord($packed[1]) & 0xc0) === 0x80)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $reasons
     * @param string[] $recommendations
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    private function makeResult(
        string $url,
        string $level,
        int $score,
        array $reasons,
        array $recommendations,
        array $providers,
        bool $cached,
        ?string $error = null
    ): array {
        $data = [
            'url' => $url,
            'threatLevel' => $level,
            'isSafe' => $level === 'safe',
            'isSuspicious' => $level === 'suspicious',
            'isBlocked' => $level === 'blocked',
            'isInvalid' => $level === 'invalid',
            'score' => $score,
            'reasons' => $reasons,
            'recommendations' => $recommendations,
            'providers' => $providers,
            'cached' => $cached,
            'checkedBy' => self::CHECKED_BY,
            'timestamp' => DateHelper::nowUtc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($error !== null) {
            $data['error'] = $error;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $providers
     * @return array<string, mixed>
     */
    private function blockedResult(
        string $url,
        string $reason,
        string $recommendation,
        ?string $error = null,
        array $providers = []
    ): array {
        return $this->makeResult(
            $url,
            'blocked',
            0,
            [$reason],
            [$recommendation],
            $providers,
            false,
            $error ?? $reason
        );
    }

    /** @return array<string, mixed> */
    private function invalidResult(string $url): array
    {
        return $this->makeResult(
            $url,
            'invalid',
            0,
            [self::MSG_INVALID],
            ['Проверьте формат URL'],
            [],
            false,
            self::MSG_INVALID
        );
    }
}
