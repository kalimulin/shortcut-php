<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ApiException;
use App\Http\Request;
use App\Services\JwtService;

final class AuthMiddleware
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function requireAuth(): callable
    {
        return function (Request $request): void {
            $token = $request->bearerToken();
            if ($token === null) {
                throw ApiException::unauthorized('Токен авторизации отсутствует');
            }

            $payload = $this->jwt->decode($token);
            $request->setAttribute('userId', (string) $payload['userId']);
            $request->setAttribute('userLogin', (string) ($payload['login'] ?? ''));
            $request->setAttribute('userRole', (string) ($payload['role'] ?? 'user'));
        };
    }

    public function requireAdmin(): callable
    {
        return function (Request $request): void {
            ($this->requireAuth())($request);
            if ($request->getAttribute('userRole') !== 'admin') {
                throw ApiException::forbidden('Доступ разрешён только администратору');
            }
        };
    }
}
