<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(?string $envPath = null)
    {
        if ($envPath !== null && is_file($envPath)) {
            $this->loadEnvFile($envPath);
            return;
        }

        $candidates = [
            dirname(__DIR__) . '/.env',
            dirname(__DIR__, 2) . '/.env',
            __DIR__ . '/../.env',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $this->loadEnvFile($path);
                return;
            }
        }
    }

    private function loadEnvFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $pos));
            $val = trim(substr($trimmed, $pos + 1));

            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }

            if ($key !== '') {
                $this->values[$key] = $val;
            }
        }
    }

    public function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return (string) $env;
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        return $default;
    }

    public function getInt(string $key, int $default): int
    {
        $raw = $this->get($key, '');
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        return (int) $raw;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = strtolower(trim($this->get($key, '')));
        if ($raw === '') {
            return $default;
        }
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    public function getList(string $key, array $default = []): array
    {
        $raw = trim($this->get($key, ''));
        if ($raw === '') {
            return $default;
        }
        $items = array_map('trim', explode(',', $raw));
        return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
    }

    public function appUrl(): string
    {
        $configured = rtrim($this->get('APP_URL', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public function shortBaseHost(): string
    {
        $configured = trim($this->get('SHORTCUT_BASE_HOST', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        return (string) (parse_url($this->appUrl(), PHP_URL_HOST) ?? 'localhost');
    }

    public function shortBaseScheme(): string
    {
        $configured = trim($this->get('SHORTCUT_BASE_SCHEME', ''));
        if ($configured !== '') {
            return $configured;
        }
        return (string) (parse_url($this->appUrl(), PHP_URL_SCHEME) ?? 'https');
    }

    public function shortRedirectPrefix(): string
    {
        return trim($this->get('SHORTCUT_REDIRECT_PREFIX', 's'), '/');
    }

    public function isDebug(): bool
    {
        return $this->getBool('APP_DEBUG', false);
    }
}
