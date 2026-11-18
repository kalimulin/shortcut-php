<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;

final class UrlTools
{
    public const SHORTCUT_CODE_REGEX = '/^[0-9A-Za-z_-]{1,64}$/';
    public const MAX_TAGS = 5;

    /** @var string[] */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function __construct(private readonly Config $config)
    {
    }

    public function buildShortUrl(string $code): string
    {
        $host = $this->config->shortBaseHost();
        $prefix = $this->config->shortRedirectPrefix();
        return $prefix !== '' ? "{$host}/{$prefix}/{$code}" : "{$host}/{$code}";
    }

    public function buildShortUrlFull(string $code): string
    {
        return $this->config->shortBaseScheme() . '://' . $this->buildShortUrl($code);
    }

    public function shortHost(): string
    {
        $hostPart = explode('/', $this->config->shortBaseHost())[0];
        $hostOnly = explode(':', $hostPart)[0];
        return self::normalizeHost($hostOnly);
    }

    public static function normalizeHost(?string $host): string
    {
        $raw = rtrim(trim((string) $host), '.');
        $value = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        if (str_starts_with($value, 'www.')) {
            return substr($value, 4);
        }
        return $value;
    }

    public static function hostMatches(string $host, string $domain): bool
    {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }

        if (function_exists('idn_to_utf8')) {
            $hUtf8 = (string) @idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $dUtf8 = (string) @idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($hUtf8 !== '' && $dUtf8 !== '' && ($hUtf8 === $dUtf8 || str_ends_with($hUtf8, '.' . $dUtf8))) {
                return true;
            }
        }

        if (function_exists('idn_to_ascii')) {
            $hAscii = (string) @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $dAscii = (string) @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($hAscii !== '' && $dAscii !== '' && ($hAscii === $dAscii || str_ends_with($hAscii, '.' . $dAscii))) {
                return true;
            }
        }

        return false;
    }

    public static function hasControlChars(string $value): bool
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 33 || $ord === 127) {
                return true;
            }
        }
        return false;
    }

    public function validateTargetFormat(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ApiException::badRequest('INVALID_URL', 'Введите ссылку');
        }
        $maxLen = $this->config->getInt('SHORTCUT_MAX_URL_LENGTH', 2048);
        if (strlen($trimmed) > $maxLen) {
            throw ApiException::badRequest('URL_TOO_LONG', "Слишком длинный URL. Максимум {$maxLen} символов");
        }
        if (preg_match('/\s/u', $trimmed) === 1) {
            throw ApiException::badRequest('INVALID_URL', 'URL не должен содержать пробелы');
        }
        return $trimmed;
    }

    /**
     * @param mixed $rawTags
     * @return string[]
     */
    public static function normalizeTags(mixed $rawTags): array
    {
        if ($rawTags === null) {
            return [];
        }
        if (!is_array($rawTags)) {
            throw ApiException::badRequest('INVALID_TAGS', 'Поле tags должно быть массивом строк');
        }
        $tags = [];
        foreach ($rawTags as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $tag = trim((string) $item);
            if ($tag === '' || in_array($tag, $tags, true)) {
                continue;
            }
            if (mb_strlen($tag) > 100) {
                throw ApiException::badRequest('INVALID_TAGS', 'Длина тега не должна превышать 100 символов');
            }
            $tags[] = $tag;
        }
        if (count($tags) > self::MAX_TAGS) {
            throw ApiException::badRequest('TOO_MANY_TAGS', 'Не больше 5 тегов');
        }
        return $tags;
    }

    /**
     * @param mixed $utm
     * @return array<string, string>
     */
    public static function cleanUtm(mixed $utm): array
    {
        if (!is_array($utm)) {
            return [];
        }
        $cleaned = [];
        foreach ($utm as $k => $v) {
            if (!is_string($k) || !in_array($k, self::UTM_KEYS, true)) {
                continue;
            }
            if ($v === null || $v === '' || !is_scalar($v)) {
                continue;
            }
            $cleaned[$k] = (string) $v;
        }
        return $cleaned;
    }

    /**
     * Склеивает UTM-метки кампании и ссылки (метки ссылки имеют наивысший приоритет,
     * метки кампании дополняют target, не перетирая уже заданные в URL).
     *
     * @param array<string, string>|null $campaignUtm
     * @param array<string, string>|null $linkUtm
     */
    public static function buildTarget(string $target, ?array $campaignUtm = null, ?array $linkUtm = null): string
    {
        $cUtm = self::cleanUtm($campaignUtm);
        $lUtm = self::cleanUtm($linkUtm);
        if ($cUtm === [] && $lUtm === []) {
            return $target;
        }

        $parsed = parse_url($target);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return $target;
        }

        $query = [];
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $query);
        }

        foreach ($cUtm as $key => $value) {
            if (!array_key_exists($key, $query)) {
                $query[$key] = $value;
            }
        }
        foreach ($lUtm as $key => $value) {
            $query[$key] = $value;
        }

        $url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        $url .= $parsed['path'] ?? '';
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $url .= '#' . $parsed['fragment'];
        }

        return $url;
    }
}
