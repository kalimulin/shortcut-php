<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity\Providers;

use App\Config;
use App\Services\UrlSecurity\HttpHelper;

final class UrlhausProvider implements SecurityProviderInterface
{
    private const ENDPOINT = 'https://urlhaus-api.abuse.ch/v1/url/';

    public function __construct(private readonly Config $config)
    {
    }

    public function getName(): string
    {
        return 'urlhaus';
    }

    public function isEnabled(): bool
    {
        // Enabled by default unless explicitly set to false
        return $this->config->getBool('URLHAUS_ENABLED', true);
    }

    public function check(string $url, string $host): array
    {
        if (!$this->isEnabled()) {
            return [
                'provider' => $this->getName(),
                'status' => 'skipped',
                'threatType' => null,
                'details' => ['reason' => 'Provider disabled'],
                'error' => null,
            ];
        }

        $timeout = $this->config->getInt('URL_HTTP_TIMEOUT_SECONDS', 3);
        $headers = [];
        $authKey = $this->config->get('URLHAUS_AUTH_KEY', '');
        if ($authKey !== '') {
            $headers['Auth-Key'] = $authKey;
        }

        $response = HttpHelper::postForm(self::ENDPOINT, ['url' => $url], $headers, $timeout);

        if ($response['error'] !== null) {
            return [
                'provider' => $this->getName(),
                'status' => 'error',
                'threatType' => null,
                'details' => ['httpCode' => $response['statusCode']],
                'error' => $response['error'],
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

        $queryStatus = $response['json']['query_status'] ?? '';
        if ($queryStatus === 'no_results') {
            return [
                'provider' => $this->getName(),
                'status' => 'clean',
                'threatType' => null,
                'details' => ['queryStatus' => 'no_results'],
                'error' => null,
            ];
        }

        if ($queryStatus === 'ok') {
            $urlStatus = $response['json']['url_status'] ?? '';
            $threat = $response['json']['threat'] ?? 'malware_download';
            return [
                'provider' => $this->getName(),
                'status' => 'threat',
                'threatType' => (string) $threat,
                'details' => [
                    'queryStatus' => 'ok',
                    'urlStatus' => $urlStatus,
                    'threat' => $threat,
                    'tags' => $response['json']['tags'] ?? [],
                ],
                'error' => null,
            ];
        }

        return [
            'provider' => $this->getName(),
            'status' => 'clean',
            'threatType' => null,
            'details' => ['queryStatus' => $queryStatus],
            'error' => null,
        ];
    }
}
