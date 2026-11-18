<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed>|null $details */
    public static function badRequest(string $code, string $message, ?array $details = null): self
    {
        return new self(400, $code, $message, $details);
    }

    /** @param array<string, mixed>|null $details */
    public static function unauthorized(
        string $message = 'Требуется авторизация',
        string $code = 'UNAUTHORIZED',
        ?array $details = null
    ): self {
        return new self(401, $code, $message, $details);
    }

    /** @param array<string, mixed>|null $details */
    public static function forbidden(
        string $codeOrMessage = 'FORBIDDEN',
        string $messageOrCode = 'Недостаточно прав',
        ?array $details = null
    ): self {
        if (str_contains($codeOrMessage, ' ') || preg_match('/[а-яА-Я]/u', $codeOrMessage)) {
            $message = $codeOrMessage;
            $code = $messageOrCode !== 'Недостаточно прав' ? $messageOrCode : 'FORBIDDEN';
        } else {
            $code = $codeOrMessage;
            $message = $messageOrCode;
        }
        return new self(403, $code, $message, $details);
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

    public static function internal(string $message = 'Внутренняя ошибка сервера', string $code = 'INTERNAL_ERROR'): self
    {
        return new self(500, $code, $message);
    }

    public static function serviceUnavailable(string $code, string $message): self
    {
        return new self(503, $code, $message);
    }
}
