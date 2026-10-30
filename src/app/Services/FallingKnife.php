<?php

namespace App\Services;

/**
 * "Cheap" that is still getting cheaper: the floor is at or under `knife_ratio` of the typical
 * price and under the 24 h median, which is itself under typical. Needs derived stats, so it is
 * false (not "unknown") until the item has enough history. Thresholds are uncalibrated; see
 * ledger.market.knife_ratio.
 */
final class FallingKnife
{
    public static function is(?int $floor, ?int $recent, ?int $typical, ?float $ratio = null): bool
    {
        if (! $floor || ! $recent || ! $typical) {
            return false;
        }

        return $floor <= $typical * ($ratio ?? config('ledger.market.knife_ratio'))
            && $floor < $recent
            && $recent < $typical;
    }
}
