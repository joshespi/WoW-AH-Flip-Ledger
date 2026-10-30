<?php

namespace App\Support;

/** Helpers for streaming query rows (cursors) that are ordered by item_id. */
final class Rows
{
    /**
     * Consecutive rows gathered per item, one item in memory at a time.
     *
     * @param  iterable<object>  $rows  ordered by item_id
     * @return \Generator<int, list<object>>
     */
    public static function byItem(iterable $rows): \Generator
    {
        $current = null;
        $group = [];

        foreach ($rows as $r) {
            $id = (int) $r->item_id;

            if ($id !== $current) {
                if ($current !== null) {
                    yield $current => $group;
                }
                $current = $id;
                $group = [];
            }

            $group[] = $r;
        }

        if ($current !== null) {
            yield $current => $group;
        }
    }

    /**
     * One column of a group as ints (query rows come back as strings on some drivers).
     *
     * @param  array<object>  $group
     * @return list<int>
     */
    public static function ints(array $group, string $column): array
    {
        return array_map('intval', array_column($group, $column));
    }
}
