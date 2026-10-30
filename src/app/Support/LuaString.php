<?php

namespace App\Support;

final class LuaString
{
    /**
     * Undo Lua string escaping: \ddd (decimal byte), \n \t \r, and \<char> for the rest.
     * One alternation of disjoint classes, so it is linear in the input.
     */
    public static function unescape(string $s): string
    {
        if (! str_contains($s, '\\')) {
            return $s;
        }

        return preg_replace_callback('/\\\\(\d{1,3}|[^\n])/', static function (array $m): string {
            $e = $m[1];

            if (ctype_digit($e)) {
                return chr(((int) $e) & 0xFF);
            }

            return match ($e) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                default => $e,
            };
        }, $s);
    }
}
