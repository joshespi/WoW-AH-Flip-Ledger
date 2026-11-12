<?php

namespace App\Support;

final class Sql
{
    /** `?,?,?` for an `in (...)` list of $n bindings. */
    public static function placeholders(int $n): string
    {
        return implode(',', array_fill(0, $n, '?'));
    }

    /** A case-insensitive "contains" pattern for `lower(col) like ?`, with LIKE wildcards in the input escaped. */
    public static function like(string $text): string
    {
        return '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
    }
}
