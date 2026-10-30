<?php

namespace App\Support;

final class Sql
{
    /** A case-insensitive "contains" pattern for `lower(col) like ?`, with LIKE wildcards in the input escaped. */
    public static function like(string $text): string
    {
        return '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
    }
}
