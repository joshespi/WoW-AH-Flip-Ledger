<?php

namespace App\Support;

final class Csv
{
    /** Keeps a spreadsheet from running text that starts with = + - @ as a formula. */
    public static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }
}
