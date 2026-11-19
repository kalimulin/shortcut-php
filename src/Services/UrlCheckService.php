<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;
use App\Repositories\BlacklistRepository;
use App\Services\UrlSecurity\UrlSecurityManager;

final class UrlCheckService
{
    public const CHECKED_BY = 'Комплексная система безопасности омни.рус';
    public const MSG_INVALID = 'Некорректный URL. Проверьте формат URL.';
    public const MSG_BLOCKED = 'Ссылка заблокирована. Перенаправление на данный ресурс запрещено соображениями безопасности.';

    private const DEFAULT_SUSPICIOUS = [
        'bit.ly', 'tinyurl.com', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly',
        'cutt.ly', 'clck.ru', 't.ly', 'rebrand.ly', 'shorturl.at', 'v.gd',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly UrlTools $urlTools,
        private readonly BlacklistRepository $blacklistRepo,
        private readonly ?UrlSecurityManager $securityManager = null,
    ) {
    }

    public function getSecurityManager(): ?UrlSecurityManager
    {
        return $this->securityManager;
    }

    /** @return array<string, mixed> */
    public function check(string $url, bool $fastPathOnly = false): array
    {
        if ($this->securityManager !== null) {
            return $this->securityManager->check($url, $fastPathOnly);
        }
        $blacklist = $this->blacklistRepo->getDomainReasonMap();
        return $this->evaluateUrl($url, $blacklist);
    }

    /** @return array<string, mixed> */
    public function ensureAllowed(string $url, bool $fastPathOnly = false): array
    {
        if ($this->securityManager !== null) {
            return $this->securityManager->ensureAllowed($url, $fastPathOnly);
        }
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

    /**
     * @param array<string, ?string> $blacklist domain => reason
     * @return array<string, mixed>
     */
    public function evaluateUrl(string $url, array $blacklist): array
    {
        $value = trim($url);
        if ($value === '' || UrlTools::hasControlChars($value)) {
            return $this->invalid($value);
        }

        $parsed = parse_url($value);
        if (!is_array($parsed)) {
            return $this->invalid($value);
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parsed['host'])) {
            return $this->invalid($value);
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return $this->invalid($value);
        }

        $rawHost = trim((string) $parsed['host'], '[]');
        $host = UrlTools::normalizeHost($rawHost);
        if ($host === '') {
            return $this->invalid($value);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return $this->blocked($value, 'Нельзя сокращать ссылки на localhost', 'Укажите публичный адрес');
        }

        if (self::isForbiddenIp($rawHost)) {
            return $this->blocked($value, 'Нельзя сокращать ссылки на внутренние адреса', 'Укажите публичный адрес');
        }

        $ownHost = $this->urlTools->shortHost();
        if ($ownHost !== '' && UrlTools::hostMatches($host, $ownHost)) {
            return $this->blocked($value, 'Нельзя сокращать короткие ссылки этого сервиса', 'Используйте исходный адрес');
        }

        foreach ($blacklist as $domain => $reason) {
            if (UrlTools::hostMatches($host, $domain)) {
                return $this->blocked(
                    $value,
                    $reason ?: "Домен «{$domain}» находится в чёрном списке",
                    'Не переходите по ссылке, если не уверены в источнике',
                    self::MSG_BLOCKED
                );
            }
        }

        $suspiciousDomains = $this->config->getList('URL_CHECK_SUSPICIOUS_DOMAINS', self::DEFAULT_SUSPICIOUS);
        foreach ($suspiciousDomains as $domain) {
            $normalized = UrlTools::normalizeHost($domain);
            if ($normalized !== '' && UrlTools::hostMatches($host, $normalized)) {
                return $this->result(
                    $value,
                    'suspicious',
                    ["Адрес ведёт на другой сервис коротких ссылок ({$domain})"],
                    ['Лучше сокращать исходный адрес, а не чужую короткую ссылку'],
                    null
                );
            }
        }

        return $this->result($value, 'safe', ['Ссылка прошла проверку безопасности'], [], null);
    }

    /** @return list<array<string, mixed>> */
    public function listBlacklist(): array
    {
        return array_map(static fn (array $row): array => [
            'entryId' => $row['id'],
            'domain' => $row['domain'],
            'reason' => $row['reason'],
            'createdAt' => DateHelper::mysqlToIso($row['created_at']),
        ], $this->blacklistRepo->listAll());
    }

    /** @return array<string, mixed> */
    public function addBlacklistEntry(string $rawDomain, ?string $reason, string $userId): array
    {
        $domain = self::normalizeBlacklistDomain($rawDomain);
        if ($this->blacklistRepo->existsByDomain($domain)) {
            throw ApiException::conflict('BLACKLIST_ENTRY_EXISTS', 'Домен уже есть в чёрном списке');
        }

        $id = Uuid::v4();
        $cleanReason = $reason !== null ? trim($reason) : null;
        if ($cleanReason === '') {
            $cleanReason = null;
        }
        $now = DateHelper::nowUtc();

        $this->blacklistRepo->create($id, $domain, $cleanReason, $userId, DateHelper::toMysqlUtc($now));

        return [
            'entryId' => $id,
            'domain' => $domain,
            'reason' => $cleanReason,
            'createdAt' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function removeBlacklistEntry(string $entryId): void
    {
        if (!Uuid::isValid($entryId)) {
            throw ApiException::notFound('BLACKLIST_ENTRY_NOT_FOUND', 'Запись чёрного списка не найдена');
        }
        $deleted = $this->blacklistRepo->delete($entryId);
        if (!$deleted) {
            throw ApiException::notFound('BLACKLIST_ENTRY_NOT_FOUND', 'Запись чёрного списка не найдена');
        }
    }

    public static function normalizeBlacklistDomain(string $value): string
    {
        $host = UrlTools::normalizeHost($value);
        if (str_contains($host, '://')) {
            $host = UrlTools::normalizeHost(explode('://', $host, 2)[1]);
        }
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        if ($host === '' || !str_contains($host, '.')) {
            throw ApiException::badRequest('INVALID_DOMAIN', 'Укажите домен, например example.com');
        }
        return $host;
    }

    private static function isForbiddenIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Если это публичный IP — разрешаем, иначе (private/reserved/loopback/link-local/multicast) блокируем
        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (!$isPublic) {
            return true;
        }

        // Дополнительная проверка multicast (224.0.0.0/4 и ff00::/8)
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
     * @return array<string, mixed>
     */
    private function result(
        string $url,
        string $level,
        array $reasons,
        array $recommendations,
        ?string $error,
    ): array {
        $scores = [
            'safe' => 100,
            'suspicious' => 50,
            'blocked' => 0,
            'invalid' => 0,
        ];

        $data = [
            'url' => $url,
            'threatLevel' => $level,
            'isSafe' => $level === 'safe',
            'isSuspicious' => $level === 'suspicious',
            'isBlocked' => $level === 'blocked',
            'isInvalid' => $level === 'invalid',
            'reasons' => $reasons,
            'score' => $scores[$level] ?? 0,
            'timestamp' => DateHelper::nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'checkedBy' => self::CHECKED_BY,
            'recommendations' => $recommendations,
        ];

        if ($error !== null) {
            $data['error'] = $error;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function blocked(string $url, string $reason, string $recommendation, ?string $error = null): array
    {
        return $this->result($url, 'blocked', [$reason], [$recommendation], $error ?? $reason);
    }

    /** @return array<string, mixed> */
    private function invalid(string $url): array
    {
        return $this->result($url, 'invalid', [self::MSG_INVALID], ['Проверьте формат URL'], self::MSG_INVALID);
    }
}
