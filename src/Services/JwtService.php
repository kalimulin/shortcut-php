<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Http\ApiException;

final class JwtService
{
    private readonly string $secret;
    private readonly int $ttlSeconds;

    public function __construct(Config $config)
    {
        $this->secret = $config->get('JWT_SECRET', 'dev-insecure-secret-change-me-please');
        $this->ttlSeconds = max(60, $config->getInt('JWT_TTL_SECONDS', 604800));
    }

    /** @param array<string, mixed> $claims */
    public function encode(array $claims): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ]);

        $segments = [
            self::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** @return array<string, mixed> */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ApiException::unauthorized('Неверный формат токена');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw ApiException::unauthorized('Неподдерживаемый алгоритм токена');
        }

        $expectedSig = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret, true);
        $actualSig = self::base64UrlDecode($encodedSignature);
        if (!hash_equals($expectedSig, $actualSig)) {
            throw ApiException::unauthorized('Подпись токена недействительна');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($payload)) {
            throw ApiException::unauthorized('Некорректные данные токена');
        }

        $exp = $payload['exp'] ?? null;
        if (!is_int($exp) && !is_numeric($exp)) {
            throw ApiException::unauthorized('Токен не содержит срока действия');
        }
        if ((int) $exp < time()) {
            throw ApiException::unauthorized('Срок действия токена истёк', 'TOKEN_EXPIRED');
        }

        if (empty($payload['userId']) || !is_string($payload['userId'])) {
            throw ApiException::unauthorized('Токен не содержит идентификатора пользователя');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
