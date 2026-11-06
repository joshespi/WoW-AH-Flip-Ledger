<?php

namespace App\Support;

final class Csv
{
    /** One CSV line, every field made safe(); no escape character, so a backslash is plain text. */
    public static function put($out, array $fields): void
    {
        fputcsv($out, array_map(self::safe(...), $fields), ',', '"', '');
    }

    /** Keeps a spreadsheet from running text that starts with = + - @ as a formula. */
    public static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }
}
