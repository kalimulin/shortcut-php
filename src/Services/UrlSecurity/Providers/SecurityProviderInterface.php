<?php

declare(strict_types=1);

namespace App\Services\UrlSecurity\Providers;

interface SecurityProviderInterface
{
    public function getName(): string;

    public function isEnabled(): bool;

    /**
     * @return array{
     *     provider: string,
     *     status: string,
     *     threatType: ?string,
     *     details: array<string, mixed>,
     *     error: ?string
     * }
     */
    public function check(string $url, string $host): array;
}
