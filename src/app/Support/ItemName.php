<?php

namespace App\Support;

final class ItemName
{
    private const MAX_LENGTH = 120;

    /** `i:238511::1:6710` -> `i:238511`; pets (`p:2843:1:3` -> `p:2843`) work the same way. */
    public static function baseId(string $itemString): string
    {
        $parts = explode(':', $itemString, 3);

        return count($parts) >= 2 ? $parts[0].':'.$parts[1] : $parts[0];
    }

    /** Numeric id for `i:123`, null for pets and anything else. */
    public static function numericId(string $baseId): ?int
    {
        return preg_match('/^i:(\d+)$/D', $baseId, $m) ? (int) $m[1] : null;
    }

    /** Id for text that is just an id ("242639" or "i:242639"), else null. Not range-checked. */
    public static function bareId(string $text): ?int
    {
        return preg_match('/^(?:i:)?(\d+)$/Di', $text, $m) ? (int) $m[1] : null;
    }

    /** What to show for an item whose name is not known yet. */
    public static function display(?string $name, int $id): string
    {
        return $name ?? 'Item #'.$id;
    }

    /** Names arrive from user uploads, so they are untrusted. Null means "not a usable name". */
    public static function sanitize(string $name): ?string
    {
        return Bounds::text($name, self::MAX_LENGTH);
    }
}
