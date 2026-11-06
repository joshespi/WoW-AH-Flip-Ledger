<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

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

    /**
     * The item_stats rows for $ids, keyed by item id. Only games with a price feed have any
     * (Game::hasMarket); the others get none.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    public static function forItems(string $region, string $game, array $ids): array
    {
        if (! Game::hasMarket($game) || $ids === []) {
            return [];
        }

        return DB::table('item_stats')->where('region', $region)->whereIn('item_id', $ids)->get()->keyBy('item_id')->all();
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
