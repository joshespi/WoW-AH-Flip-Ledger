<?php

namespace App\Services;

use App\Support\ItemName;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Reads a trades CSV (`time,item,qty,cost,note`). `item` is `i:ID` or an item name that
 * matches exactly one id; `cost` is the lot total in gold text ("2730.82", "2,730g 82s").
 * Times without an offset are read in the given timezone.
 */
class TradesCsvParser
{
    /** `note` is optional. */
    private const REQUIRED = ['time', 'item', 'qty', 'cost'];

    /** ISO 8601 date, optional time and optional offset. PHP's own parser would also take "now" or "+1 week". */
    private const ISO_TIME = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/D';

    /**
     * @param  array<string, string>  $names  base item id => name
     * @return list<array{ts: int, item: string, qty: int, cost: int, note: string, line: int}>
     *
     * @throws InvalidArgumentException with the CSV line number in the message
     */
    public function parse(string $csv, array $names, DateTimeZone $tz): array
    {
        $byName = [];

        foreach ($names as $id => $name) {
            $byName[mb_strtolower($name)][$id] = true;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            (array) fgetcsv($stream, null, ',', '"', ''),
        );

        if ($missing = array_diff(self::REQUIRED, $header)) {
            throw new InvalidArgumentException('line 1: the first row must be a header with the columns time,item,qty,cost,note (missing: '.implode(', ', $missing).').');
        }

        // array_combine() below would silently keep only the last of two same-named columns.
        if ($repeated = array_unique(array_diff_assoc($header, array_unique($header)))) {
            throw new InvalidArgumentException('line 1: each column may appear only once (repeated: '.implode(', ', $repeated).').');
        }

        $trades = [];
        $line = 1;

        while (($f = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;

            if ($f === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($f, 0, count($header)), count($header), ''));

            try {
                // The CSV line travels with the trade so later checks report it the same way.
                $trades[] = $this->row($row, $byName, $tz) + ['line' => $line];
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("line {$line}: ".$e->getMessage(), previous: $e);
            }
        }

        fclose($stream);

        return $trades;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, array<string, true>>  $byName
     * @return array{ts: int, item: string, qty: int, cost: int, note: string}
     */
    private function row(array $row, array $byName, DateTimeZone $tz): array
    {
        $item = trim($row['item'] ?? '');

        if (! str_starts_with($item, 'i:')) {
            $ids = array_keys($byName[mb_strtolower($item)] ?? []);

            if (count($ids) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" matches %d items, use i:ID', $item, count($ids)));
            }

            $item = $ids[0];
        }

        $qty = trim($row['qty'] ?? '');

        if (! ctype_digit($qty)) {
            throw new InvalidArgumentException("bad qty \"{$qty}\"");
        }

        $time = trim($row['time'] ?? '');

        try {
            if (! preg_match(self::ISO_TIME, $time)) {
                throw new InvalidArgumentException('not ISO');
            }

            $ts = (new DateTimeImmutable($time, $tz))->getTimestamp();
        } catch (Throwable) {
            throw new InvalidArgumentException("bad time \"{$time}\" (use 2026-10-12T10:51 or 2026-10-12T10:51-06:00)");
        }

        return [
            'ts' => $ts,
            'item' => ItemName::baseId($item),
            'qty' => (int) $qty,
            'cost' => Money::parseGold($row['cost'] ?? ''),
            'note' => trim($row['note'] ?? ''),
        ];
    }
}
