<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Chunked bulk writes, so one statement never grows with the size of an upload or snapshot. */
final class Bulk
{
    public const CHUNK = 500;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int rows actually inserted (existing keys are ignored)
     */
    public static function insertOrIgnore(string $table, array $rows): int
    {
        $new = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $new += DB::table($table)->insertOrIgnore($chunk);
        }

        return $new;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update  columns to overwrite when the key exists
     * @return int rows submitted
     */
    public static function upsert(string $table, array $rows, array $uniqueBy, array $update): int
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
        }

        return count($rows);
    }
}
