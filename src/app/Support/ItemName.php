<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class ItemName
{
    private const MAX_LENGTH = 120;

    // items.name_source; precedence is Blizzard over TSM over none.
    public const SOURCE_NONE = 'none';

    public const SOURCE_TSM = 'tsm';

    public const SOURCE_BLIZZARD = 'blizzard';

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

    /**
     * Catalog ids per name, matched case-insensitively (lowercased on both sides: MariaDB's
     * collation ignores case already, SQLite's does not). A name is only usable when it maps to
     * exactly one id; callers check that, never guess.
     *
     * @param  list<string>  $names
     * @return array<string, list<int>> lowercased name => ids
     */
    public static function idsByName(array $names, bool $commoditiesOnly = false): array
    {
        $ids = [];

        foreach (array_chunk(array_values(array_unique(array_map('mb_strtolower', $names))), Bulk::CHUNK) as $chunk) {
            $q = DB::table('items')->whereRaw('lower(name) in ('.Sql::placeholders(count($chunk)).')', $chunk);

            foreach (($commoditiesOnly ? $q->where('is_commodity', 1) : $q)->get(['id', 'name']) as $item) {
                $ids[mb_strtolower($item->name)][] = (int) $item->id;
            }
        }

        return $ids;
    }

    /** Names arrive from user uploads, so they are untrusted. Null means "not a usable name". */
    public static function sanitize(string $name): ?string
    {
        return Bounds::text($name, self::MAX_LENGTH);
    }
}
