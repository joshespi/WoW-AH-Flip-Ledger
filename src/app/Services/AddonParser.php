<?php

namespace App\Services;

use App\Support\Bounds;
use App\Support\Game;

/**
 * Reads WowFlipLedger.lua, the companion addon's own SavedVariables, into the same TsmData shape
 * TsmParser makes, so ingest treats both alike. The file is untrusted upload data: a line scanner
 * with anchored patterns, size, line and row caps.
 *
 * The addon writes one record per string ("kind|time|realm|item|qty|a|b"), which WoW's serializer
 * puts on its own line. Only the `events` and `inv` lists, the `last` time and the client's
 * `interface` number (which game wrote the file) are read; everything else in the file
 * (per-character snapshots, learned names) is skipped.
 */
class AddonParser
{
    public const HEADER = 'WowFlipLedgerDB = {';

    // WoW writes no indentation (older clients used tabs) and an optional "-- [n]" after array
    // entries; lines are trimmed and the nesting is tracked by braces instead.
    private const LAST = '/^\["last"\] = (\d{1,12}),$/D';

    private const INTERFACE = '/^\["interface"\] = (\d{1,7}),$/D';

    private const ENTRY = '/^"([^"\\\\]{1,200})",(?: -- \[\d+\])?$/D';

    private const INV = '/^(\d{1,9}):(\d{1,15})$/D';

    private const REALM = '/^[A-Za-z0-9\' -]{1,64}$/Du';

    public function __construct(
        private readonly int $maxBytes = TsmParser::MAX_BYTES,
        private readonly int $maxLines = TsmParser::MAX_LINES,
        private readonly int $maxRows = TsmParser::MAX_ROWS,
    ) {}

    public static function looksLike(string $contents): bool
    {
        return str_starts_with(ltrim(substr($contents, 0, 64), "\xEF\xBB\xBF \t\r\n"), self::HEADER);
    }

    /**
     * @throws TsmParseException
     */
    public function parse(string $contents): TsmData
    {
        if (strlen($contents) > $this->maxBytes) {
            throw new TsmParseException('That file is too large to be a WoW Flip Ledger data file.');
        }

        if (! self::looksLike($contents)) {
            throw new TsmParseException('That does not look like WowFlipLedger.lua (no WowFlipLedgerDB table).');
        }

        $csv = [];
        $inventory = [];
        $last = null;
        $interface = null;
        $mode = null;
        $depth = 0;
        $rows = 0;
        foreach (LuaLines::of($contents, $this->maxLines, 'WoW Flip Ledger') as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Depth is counted from lines that open a table ("... = {") or close one ("}").
            $opens = str_ends_with($line, '{');
            $closes = $line[0] === '}';

            if ($mode !== null && $depth === 2) {
                if ($closes) {
                    $mode = null;
                } elseif (preg_match(self::ENTRY, $line, $m)) {
                    if ($mode === 'inv') {
                        if (preg_match(self::INV, $m[1], $i)) {
                            $inventory['i:'.$i[1]] = ($inventory['i:'.$i[1]] ?? 0) + (int) $i[2];
                        }
                    } elseif (($row = $this->event($m[1], byName: $mode === 'unresolved')) !== null) {
                        if (++$rows > $this->maxRows) {
                            throw new TsmParseException('That file has too many log entries to be a WoW Flip Ledger data file.');
                        }

                        $csv[$row[0]][] = $row[1];
                    }
                }

            } elseif ($depth === 1) {
                if ($line === '["events"] = {') {
                    $mode = 'events';
                } elseif ($line === '["unresolved"] = {') {
                    $mode = 'unresolved';
                } elseif ($line === '["inv"] = {') {
                    $mode = 'inv';
                } elseif (preg_match(self::LAST, $line, $m)) {
                    $last = (int) $m[1];
                } elseif (preg_match(self::INTERFACE, $line, $m)) {
                    $interface = (int) $m[1];
                }
            }

            if ($opens) {
                $depth++;
            } elseif ($closes) {
                $depth--;
            }
        }

        // When the addon last saved (logout or /reload): the inventory snapshot is as of then.
        return new TsmData(
            [], $csv, $inventory,
            origin: TsmData::ORIGIN_ADDON,
            takenAt: $last !== null && Bounds::plausibleTime($last) ? $last : null,
            game: $interface === null ? null : Game::fromInterface($interface),
        );
    }

    /**
     * One record as [csv section, row of strings], or null when it is not a valid record. Rows use
     * TSM's csv field names so ingest's validation is shared. `price` is per unit.
     *
     * $byName: a sale the addon could not put an id on, with the item name in place of the id.
     * Ingest matches the name against the item catalog and skips it if that is not exact.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function event(string $record, bool $byName = false): ?array
    {
        $f = explode('|', $record);
        $kind = $f[0];

        if ($byName && ($kind !== 'sale' || ! preg_match('/^[^\p{C}|]{1,100}$/Du', $f[3] ?? ''))) {
            return null;
        }

        $want = match ($kind) {
            'expired', 'cancelled' => 5,
            'buy' => 6,
            'sale' => 7,
            default => null,
        };

        if ($want === null || count($f) !== $want || ! preg_match(self::REALM, $f[2])) {
            return null;
        }

        $time = Bounds::count($f[1]);
        $item = $byName ? 1 : Bounds::count($f[3], Bounds::MAX_ITEM_ID);
        $qty = Bounds::count($f[4], Bounds::MAX_QTY);

        if ($time === null || $item === null || $qty === null || $qty < 1) {
            return null;
        }

        $row = ['realm' => $f[2], 'itemString' => $byName ? '' : 'i:'.$item, 'quantity' => (string) $qty, 'time' => (string) $time]
            + ($byName ? ['itemName' => $f[3]] : []);

        if ($kind === 'buy') {
            $total = Bounds::count($f[5], Bounds::MAX_COST);

            return $total === null ? null : ['csvBuys', $row + ['source' => TsmData::SOURCE_AUCTION, 'price' => (string) intdiv($total, $qty), 'total' => (string) $total]];
        }

        if ($kind === 'sale') {
            $gross = Bounds::count($f[5], Bounds::MAX_COST);
            $cut = Bounds::count($f[6], Bounds::MAX_COST);
            $net = $gross === null || $cut === null ? null : $gross - $cut;

            return $net === null || $net < 1 ? null : ['csvSales', $row + ['source' => TsmData::SOURCE_AUCTION, 'price' => (string) intdiv($net, $qty)]];
        }

        return [$kind === 'expired' ? 'csvExpired' : 'csvCancelled', $row];
    }
}
