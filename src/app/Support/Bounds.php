<?php

namespace App\Support;

/**
 * Plausibility limits shared by everything that accepts user data (TSM ingest, trade imports),
 * so a sale and a buy are judged by the same rules.
 */
final class Bounds
{
    /** 2004-01-01: before WoW existed, so anything earlier is not a real time. */
    public const MIN_TIME = 1_072_915_200;

    /** Latest accepted time ahead of now; two days covers every timezone and a skewed clock. */
    public const MAX_FUTURE_SECONDS = 2 * 86400;

    // Money and quantity limits keep qty x price inside a signed 64-bit integer (copper).
    public const MAX_QTY = 10_000_000;

    /** Per unit. */
    public const MAX_PRICE = 100_000_000_000;

    /** Per lot: 100,000,000 gold. */
    public const MAX_COST = 1_000_000_000_000;

    public const MAX_INVENTORY = 1_000_000_000_000;

    public const MAX_ITEM_ID = 999_999_999;

    public const MAX_NOTE = 255;

    /** Free text for a trade or watchlist note (see text()). */
    public static function note(?string $note): ?string
    {
        return self::text($note, self::MAX_NOTE);
    }

    /**
     * Untrusted free text that is shown back (pages, mail, the addon): valid UTF-8, control and
     * format characters (bidi overrides included) dropped, whitespace collapsed, capped at $max
     * characters. Null when nothing is left.
     */
    public static function text(?string $text, int $max): ?string
    {
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', mb_scrub((string) $text, 'UTF-8')) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max, 'UTF-8');
    }

    /** TSM rows are untrusted text; only plain non-negative integers up to $max count. */
    public static function count(mixed $v, int $max = PHP_INT_MAX): ?int
    {
        if (! is_string($v) || strlen($v) > 18 || ! ctype_digit($v)) {
            return null;
        }

        $n = (int) $v;

        return $n <= $max ? $n : null;
    }

    public static function validItemId(int $id): bool
    {
        return $id >= 1 && $id <= self::MAX_ITEM_ID;
    }

    /** A whole number typed into a form (digits only, at most 9), or null. */
    public static function wholeNumber(string $text): ?int
    {
        $text = trim($text);

        return strlen($text) <= 9 ? self::count($text) : null;
    }

    /**
     * The quantity box of a buy form.
     *
     * @throws \InvalidArgumentException
     */
    public static function quantityInput(string $text): int
    {
        return self::wholeNumber($text) ?? throw new \InvalidArgumentException('Quantity must be a whole number.');
    }

    /**
     * What is wrong with a buy's item id, quantity, cost or time, as a lowercase fragment
     * ("quantity must be between ..."), or null when it is fine. Shared by hand entry and CSV import.
     */
    public static function tradeProblem(int $itemId, int $qty, int $cost, int $ts): ?string
    {
        return match (true) {
            ! self::validItemId($itemId) => 'item id is out of range',
            $qty < 1 || $qty > self::MAX_QTY => 'quantity must be between 1 and '.number_format(self::MAX_QTY),
            $cost < 0 || $cost > self::MAX_COST => 'cost must be between 0 and '.number_format(self::MAX_COST / Money::COPPER_PER_GOLD).' gold',
            ! self::plausibleTime($ts) => 'time is outside the plausible range',
            default => null,
        };
    }

    /** A real time: after WoW existed and not further than $futureSeconds past $now (default: the clock). */
    public static function plausibleTime(int $ts, int $futureSeconds = self::MAX_FUTURE_SECONDS, ?int $now = null): bool
    {
        return $ts >= self::MIN_TIME && $ts <= ($now ?? time()) + $futureSeconds;
    }
}
