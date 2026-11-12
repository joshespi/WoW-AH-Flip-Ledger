<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is copper integers: 1g = 100s = 10,000c. Rounding is half-to-even to match the
 * legacy Python tool (round() there is banker's rounding), so parity tests hold on x.5 values.
 */
final class Money
{
    public const AH_CUT = 0.05;

    /** What a sale nets after the AH cut, per copper of price. */
    public const NET = 1 - self::AH_CUT;

    public const COPPER_PER_GOLD = 10_000;

    /** parseGold input is user text; cap it so the unit regex can't be fed a long whitespace run. */
    private const MAX_INPUT_LENGTH = 100;

    public static function round(float|int $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_EVEN);
    }

    /** The listing price that nets `$marginPct` over `$cost` after the AH cut; 0 is break-even. */
    public static function sellPrice(float|int $cost, float|int $marginPct = 0): float
    {
        return $cost * (1 + $marginPct / 100) / self::NET;
    }

    /** Per-unit copper of a lot (floored); a zero quantity counts as one. */
    public static function unit(int $total, int $qty): int
    {
        return intdiv($total, max(1, $qty));
    }

    /**
     * @return array{sign: string, g: int, s: int, c: int}
     */
    public static function parts(float|int $copper): array
    {
        $c = self::round($copper);
        $sign = $c < 0 ? '-' : '';
        $c = abs($c);

        return [
            'sign' => $sign,
            'g' => intdiv($c, self::COPPER_PER_GOLD),
            's' => intdiv($c, 100) % 100,
            'c' => $c % 100,
        ];
    }

    /**
     * The display rules in one place, for fmt() and the colored <x-money> component: gold only
     * from 1g up (thousands separated), silver padded only after gold, copper always padded.
     *
     * @return array{sign: string, units: list<array{string, string}>} units as [letter, digits]
     */
    public static function segments(float|int $copper): array
    {
        ['sign' => $sign, 'g' => $g, 's' => $s, 'c' => $c] = self::parts($copper);

        $units = $g > 0 ? [['g', number_format($g)], ['s', sprintf('%02d', $s)]] : [['s', (string) $s]];
        $units[] = ['c', sprintf('%02d', $c)];

        return ['sign' => $sign, 'units' => $units];
    }

    /** `1,234g 56s 78c`; under 1g it drops the gold and the silver padding: `5s 03c`. */
    public static function fmt(float|int $copper): string
    {
        ['sign' => $sign, 'units' => $units] = self::segments($copper);

        return $sign.implode(' ', array_map(fn (array $u) => $u[1].$u[0], $units));
    }

    /**
     * "5031.74" (gold), "5031g 74s 2c" or "2,730g 82s" -> copper.
     *
     * @throws InvalidArgumentException when the text is neither a unit string nor a number
     */
    public static function parseGold(string $text): int
    {
        $s = str_replace(',', '', trim($text));

        if ($s === '' || strlen($s) > self::MAX_INPUT_LENGTH) {
            throw new InvalidArgumentException('Not a gold amount.');
        }

        // A leading minus on the unit form is an extension over the legacy tool, so fmt()'s
        // negative output round-trips. At least one unit must be present ("-" alone is not 0).
        // Digit runs are capped at 12 here and below, so the result can't overflow an int.
        if (preg_match('/^(-?)(?:(\d{1,12})g)?\s*(?:(\d{1,12})s)?\s*(?:(\d{1,12})c)?$/D', $s, $m)
            && ($m[2] ?? '').($m[3] ?? '').($m[4] ?? '') !== '') {
            $copper = ((int) ($m[2] ?? 0)) * self::COPPER_PER_GOLD + ((int) ($m[3] ?? 0)) * 100 + (int) ($m[4] ?? 0);

            return $m[1] === '-' ? -$copper : $copper;
        }

        if (preg_match('/^[+-]?(?:\d{1,12}(?:\.\d*)?|\.\d+)$/D', $s)) {
            return self::round((float) $s * self::COPPER_PER_GOLD);
        }

        throw new InvalidArgumentException('Not a gold amount.');
    }
}
