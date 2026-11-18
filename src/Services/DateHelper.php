<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DateHelper
{
    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function toMysqlUtc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function toMysqlUtcMillis(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function parseUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($trimmed, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function mysqlToIso(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $dt = self::parseUtc($value);
        return $dt?->format('Y-m-d\TH:i:s\Z');
    }
}
