<?php

namespace App\Support;

final class Stats
{
    /**
     * Median of copper amounts, rounded half up. The two middle values average for an even count.
     *
     * @param  non-empty-list<int>  $values
     */
    public static function median(array $values): int
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : intdiv($values[$mid - 1] + $values[$mid] + 1, 2);
    }

    /**
     * @param  non-empty-list<int>  $values
     */
    public static function mean(array $values): int
    {
        return intdiv(array_sum($values) + intdiv(count($values), 2), count($values));
    }
}
