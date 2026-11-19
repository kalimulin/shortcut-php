<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity;

final class HttpHelper
{
    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, json: ?array<string, mixed>, error: ?string}
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 3): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers['Content-Type'] = 'application/json';
        return self::request('POST', $url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $data
     * @return array{statusCode: int, body: string, json: ?array<string, mixed>, error: ?string}
     */
    public static function postForm(string $url, array $data, array $headers = [], int $timeout = 3): array
    {
        $body = http_build_query($data);
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return self::request('POST', $url, $headers, $body, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, json: ?array<string, mixed>, error: ?string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 3): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, json: ?array<string, mixed>, error: ?string}
     */
    private static function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return [
                'statusCode' => 0,
                'body' => '',
                'json' => null,
                'error' => 'cURL extension is not installed',
            ];
        }

        $ch = curl_init();
        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = "{$k}: {$v}";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(2, $timeout));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ShortcutSecurityChecker/1.0 (+https://омни.рус)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $raw = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            return [
                'statusCode' => $statusCode,
                'body' => '',
                'json' => null,
                'error' => $curlError ?: 'Unknown cURL error',
            ];
        }

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $testJson = json_decode($raw, true);
            if (is_array($testJson)) {
                $decoded = $testJson;
            }
        }

        return [
            'statusCode' => $statusCode,
            'body' => (string) $raw,
            'json' => $decoded,
            'error' => null,
        ];
    }
}
