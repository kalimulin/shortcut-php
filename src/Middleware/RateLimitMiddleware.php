<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ApiException;
use App\Http\Request;
use App\Repositories\RateLimitRepository;

final class RateLimitMiddleware
{
    public function __construct(private readonly RateLimitRepository $repo)
    {
    }

    public function limit(string $scope, int $maxRequests, int $windowSeconds = 60): callable
    {
        return function (Request $request) use ($scope, $maxRequests, $windowSeconds): void {
            if ($maxRequests <= 0) {
                return;
            }
            $bucketKey = $scope . ':' . hash('sha256', $request->clientIp);
            $allowed = $this->repo->hit($bucketKey, $maxRequests, $windowSeconds);
            if (!$allowed) {
                throw ApiException::tooManyRequests();
            }
        };
    }
}
