<?php

namespace App\Support;

/**
 * The one rule for showing derived market numbers (recent, typical, 60d, sold per day): only
 * once an item has ledger.prices.min_days of snapshot history. The floor is exact and never
 * gated. Works on any row shaped like item_stats (`days_of_data` plus the numbers), including a
 * left join that found no stats at all (null).
 */
final class MarketStats
{
    public static function minDays(): int
    {
        return (int) config('ledger.prices.min_days');
    }

    public static function ready(?object $stat): bool
    {
        return $stat !== null && $stat->days_of_data !== null && (int) $stat->days_of_data >= self::minDays();
    }

    /** A derived column as an int, or null while the item is still collecting data (or has no value). */
    public static function derived(?object $stat, string $column): ?int
    {
        return self::ready($stat) && $stat->{$column} !== null ? (int) $stat->{$column} : null;
    }
}
