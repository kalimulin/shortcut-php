<?php

declare(strict_types=1);

namespace App\Services;

final class Base62
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function hexToBase62(string $hex): string
    {
        $num = (int) hexdec($hex);
        if ($num === 0) {
            return '0';
        }

        $result = '';
        while ($num > 0) {
            $result = self::ALPHABET[$num % 62] . $result;
            $num = intdiv($num, 62);
        }

        return $result;
    }

    public static function fromUuid(string $uuid): string
    {
        $hex = substr(str_replace('-', '', $uuid), 0, 8);
        return self::hexToBase62($hex);
    }
}
