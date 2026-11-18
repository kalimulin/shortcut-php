<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $rawBody,
        public readonly string $clientIp,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        // Нормализуем префикс /api (работаем одинаково с /api/... и при прямом вызове api.php)
        if ($path === '/api' || $path === '/api/') {
            $path = '/';
        } elseif (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        }

        // Убираем завершающий слэш (кроме корня)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $headers = self::collectHeaders();
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_scalar($v)) {
                $query[(string) $k] = (string) $v;
            }
        }

        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_scalar($v)) {
                $cookies[(string) $k] = (string) $v;
            }
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = '';
        }

        $clientIp = self::detectClientIp($headers);

        return new self($method, $path, $query, $headers, $cookies, $rawBody, $clientIp);
    }

    /** @return array<string, string> */
    private static function collectHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $k => $v) {
                    $headers[strtolower((string) $k)] = (string) $v;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] ??= $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] ??= $value;
            }
        }

        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (is_string($auth) && $auth !== '') {
                $headers['authorization'] = $auth;
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private static function detectClientIp(array $headers): string
    {
        $forwarded = $headers['x-forwarded-for'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $realIp = $headers['x-real-ip'] ?? '';
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null) {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) === 1) {
            $token = trim($m[1]);
            return $token !== '' ? $token : null;
        }
        return null;
    }

    public function queryParam(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function queryInt(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $raw = $this->queryParam($name);
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }
        $val = (int) $raw;
        return max($min, min($max, $val));
    }

    public function queryBool(string $name): ?bool
    {
        $raw = $this->queryParam($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        $trimmed = trim($this->rawBody);
        if ($trimmed === '') {
            $this->parsedBody = !empty($_POST) ? $_POST : [];
            return $this->parsedBody;
        }

        $contentType = strtolower((string) $this->header('content-type', ''));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody, $parsed);
            $this->parsedBody = is_array($parsed) ? $parsed : [];
            return $this->parsedBody;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            throw ApiException::badRequest('INVALID_JSON', 'Некорректное JSON-тело запроса');
        }
        $this->parsedBody = $decoded;
        return $this->parsedBody;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
