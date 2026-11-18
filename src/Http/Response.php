<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

final class Response
{
    public static function applyCors(Config $config): void
    {
        $origin = trim($config->get('CORS_ALLOWED_ORIGIN', ''));
        if ($origin === '') {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Vary: Origin');
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    /** @param array<string, mixed>|null $details */
    public static function error(int $statusCode, string $code, string $message, ?array $details = null): never
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        self::json($payload, $statusCode);
    }

    /** @param array<string, string> $headers */
    public static function raw(string $body, string $contentType, int $statusCode = 200, array $headers = []): never
    {
        http_response_code($statusCode);
        header('Content-Type: ' . $contentType);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }
}
