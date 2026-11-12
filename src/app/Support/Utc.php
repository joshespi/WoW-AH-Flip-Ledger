<?php

namespace App\Support;

/** Database times are UTC `Y-m-d H:i:s` strings; these are the only two conversions the app needs. */
final class Utc
{
    public const HOUR = 3600;

    public const DAY = 86400;

    /** Epoch seconds $days before $from (default: now). */
    public static function daysAgo(int $days, ?int $from = null): int
    {
        return ($from ?? time()) - $days * self::DAY;
    }

    /** Epoch seconds as a UTC database string (now when null). */
    public static function sql(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? time());
    }

    /** Epoch seconds as a UTC `Y-m-d` day (today when null). */
    public static function day(?int $ts = null): string
    {
        return gmdate('Y-m-d', $ts ?? time());
    }

    /** A UTC database string as epoch seconds. */
    public static function ts(string $utc): int
    {
        return (int) strtotime($utc.' UTC');
    }
}
