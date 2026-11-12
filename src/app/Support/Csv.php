<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class Csv
{
    public const HEADERS = ['Content-Type' => 'text/csv; charset=UTF-8'];

    /** One CSV line, every field made safe(); no escape character, so a backslash is plain text. */
    public static function put($out, array $fields): void
    {
        fputcsv($out, array_map(self::safe(...), $fields), ',', '"', '');
    }

    /**
     * A header line, then one line per row ($map turns a row into its fields).
     *
     * @param  resource  $out
     * @param  list<string>  $header
     * @param  iterable<mixed>  $rows
     */
    public static function write($out, array $header, iterable $rows, callable $map): void
    {
        self::put($out, $header);

        foreach ($rows as $row) {
            self::put($out, $map($row));
        }
    }

    /** write() as a streamed download. */
    public static function download(string $name, array $header, iterable $rows, callable $map): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows, $map) {
            $out = fopen('php://output', 'w');
            self::write($out, $header, $rows, $map);
            fclose($out);
        }, $name, self::HEADERS);
    }

    /** Keeps a spreadsheet from running text that starts with = + - @ as a formula. */
    public static function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }
}
