<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity\Providers;

use App\Config;
use App\Services\UrlSecurity\HttpHelper;

final class VirusTotalProvider implements SecurityProviderInterface
{
    private const BASE_URL = 'https://www.virustotal.com/api/v3';

    public function __construct(private readonly Config $config)
    {
    }

    public function getName(): string
    {
        return 'virustotal';
    }

    public function isEnabled(): bool
    {
        return $this->config->get('VIRUSTOTAL_API_KEY', '') !== '';
    }

    public function check(string $url, string $host): array
    {
        $key = $this->config->get('VIRUSTOTAL_API_KEY', '');
        if ($key === '') {
            return [
                'provider' => $this->getName(),
                'status' => 'skipped',
                'threatType' => null,
                'details' => ['reason' => 'API key not configured'],
                'error' => null,
            ];
        }

        $timeout = $this->config->getInt('URL_HTTP_TIMEOUT_SECONDS', 3);
        $headers = [
            'x-apikey' => $key,
            'Accept' => 'application/json',
        ];

        // 1. Try URL lookup via base64url identifier
        $urlId = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
        $endpoint = self::BASE_URL . '/urls/' . $urlId;

        $response = HttpHelper::get($endpoint, $headers, $timeout);

        // If URL not found in VT database (404), fallback to domain reputation
        if ($response['statusCode'] === 404 && $host !== '') {
            $domainEndpoint = self::BASE_URL . '/domains/' . urlencode($host);
            $response = HttpHelper::get($domainEndpoint, $headers, $timeout);
        }

        if ($response['error'] !== null) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => $response['statusCode']],
                'error' => $response['error'],
            ];
        }

        if ($response['statusCode'] === 429) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => 429, 'reason' => 'VirusTotal rate limit exceeded'],
                'error' => 'Rate limit 429',
            ];
        }

        if ($response['statusCode'] === 404) {
            // Not in VT database -> clean / unflagged
            return [
                'provider' => $this->getName(),
                'status' => 'clean',
                'threatType' => null,
                'details' => ['inDatabase' => false],
                'error' => null,
            ];
        }

        if ($response['statusCode'] !== 200 || !is_array($response['json'])) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => $response['statusCode']],
                'error' => "HTTP {$response['statusCode']}",
            ];
        }

        $stats = $response['json']['data']['attributes']['last_analysis_stats'] ?? [];
        $malicious = (int) ($stats['malicious'] ?? 0);
        $suspicious = (int) ($stats['suspicious'] ?? 0);
        $harmless = (int) ($stats['harmless'] ?? 0);

        if ($malicious >= 2) {
            return [
                'provider' => $this->getName(),
                'status' => 'threat',
                'threatType' => 'MALWARE_OR_PHISHING',
                'details' => [
                    'malicious' => $malicious,
                    'suspicious' => $suspicious,
                    'harmless' => $harmless,
                ],
                'error' => null,
            ];
        }

        if ($malicious === 1 || $suspicious >= 2) {
            return [
                'provider' => $this->getName(),
                'status' => 'suspicious',
                'threatType' => 'SUSPICIOUS_DETECTION',
                'details' => [
                    'malicious' => $malicious,
                    'suspicious' => $suspicious,
                    'harmless' => $harmless,
                ],
                'error' => null,
            ];
        }

        return [
            'provider' => $this->getName(),
            'status' => 'clean',
            'threatType' => null,
            'details' => [
                'malicious' => $malicious,
                'suspicious' => $suspicious,
                'harmless' => $harmless,
            ],
            'error' => null,
        ];
    }
}
