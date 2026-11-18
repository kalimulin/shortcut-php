<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Требуется авторизация', string $code = 'UNAUTHORIZED'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'Недостаточно прав', string $code = 'FORBIDDEN'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self(422, $code, $message);
    }

    public static function tooManyRequests(string $message = 'Слишком много запросов. Повторите позже.'): self
    {
        return new self(429, 'RATE_LIMIT_EXCEEDED', $message);
    }

    public static function serviceUnavailable(string $code, string $message): self
    {
        return new self(503, $code, $message);
    }
}
