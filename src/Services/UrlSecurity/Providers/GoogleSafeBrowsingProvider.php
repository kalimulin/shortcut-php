<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity\Providers;

use App\Config;
use App\Services\UrlSecurity\HttpHelper;

final class GoogleSafeBrowsingProvider implements SecurityProviderInterface
{
    private const ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    public function __construct(private readonly Config $config)
    {
    }

    public function getName(): string
    {
        return 'google_safe_browsing';
    }

    public function isEnabled(): bool
    {
        return $this->config->get('GOOGLE_SAFE_BROWSING_KEY', '') !== '';
    }

    public function check(string $url, string $host): array
    {
        $key = $this->config->get('GOOGLE_SAFE_BROWSING_KEY', '');
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
        $endpoint = self::ENDPOINT . '?key=' . urlencode($key);

        $payload = [
            'client' => [
                'clientId' => 'shortcut-security',
                'clientVersion' => '1.0.0',
            ],
            'threatInfo' => [
                'threatTypes' => [
                    'MALWARE',
                    'SOCIAL_ENGINEERING',
                    'UNWANTED_SOFTWARE',
                    'POTENTIALLY_HARMFUL_APPLICATION',
                ],
                'platformTypes' => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries' => [
                    ['url' => $url],
                ],
            ],
        ];

        $response = HttpHelper::postJson($endpoint, $payload, [], $timeout);

        if ($response['error'] !== null) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => $response['statusCode']],
                'error' => $response['error'],
            ];
        }

        if ($response['statusCode'] !== 200) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => $response['statusCode'], 'body' => mb_substr($response['body'], 0, 500)],
                'error' => "HTTP {$response['statusCode']}",
            ];
        }

        $matches = $response['json']['matches'] ?? [];
        if (!empty($matches) && is_array($matches)) {
            $firstMatch = $matches[0];
            $threatType = is_array($firstMatch) ? ($firstMatch['threatType'] ?? 'MALWARE') : 'MALWARE';
            return [
                'provider' => $this->getName(),
                'status' => 'threat',
                'threatType' => (string) $threatType,
                'details' => ['matches' => $matches],
                'error' => null,
            ];
        }

        return [
            'provider' => $this->getName(),
            'status' => 'clean',
            'threatType' => null,
            'details' => ['matches' => 0],
            'error' => null,
        ];
    }
}
