<?php

namespace App\Support;

final class Num
{
    /** Database values arrive as numeric strings or null; keep null as null. */
    public static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /**
     * floor($a * $b / $c) for non-negative ints, without forming $a * $b (which can pass
     * PHP_INT_MAX for a large lot cost times a large quantity). Same result as intdiv($a * $b, $c).
     */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        return intdiv($a, $c) * $b + intdiv($a % $c * $b, $c);
    }
}
