<?php

namespace App\Services;

use App\Support\ItemName;
use App\Support\LuaString;

/**
 * Reads TradeSkillMaster.lua (SavedVariables). The file is untrusted upload data, so this is a
 * line scanner with anchored, non-nested patterns rather than regexes over whole string
 * values: every step is linear, and size, line and row counts are capped.
 *
 * TSM writes one entry per line and escapes newlines inside strings, so a string value is
 * the rest of its line. A line that opens a string but doesn't end it is skipped.
 */
class TsmParser
{
    public const MAX_BYTES = 48 * 1024 * 1024;

    public const MAX_LINES = 1_000_000;

    public const MAX_ROWS = 300_000;

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxLines = self::MAX_LINES,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    private const INVENTORY_HEADER = '/^\["[sg]@[^"]*@internalData@(?:bag|bank|reagentBank|auction|mail|warbank)Quantity"\] = \{$/D';

    private const CSV_HEADER = '/^\["r@([^@"]*)@internalData@(csv\w+)"\] = "/D';

    private const INVENTORY_ENTRY = '/^\["(i:\d+)[^"]*"\] = (\d+),?$/D';

    private const SALE_HINT = '/^\["([^"\x01]+)\x01(i:\d+)/';

    /**
     * @throws TsmParseException
     */
    public function parse(string $contents): TsmData
    {
        if (strlen($contents) > $this->maxBytes) {
            throw new TsmParseException('That file is too large to be a TradeSkillMaster data file.');
        }

        if (! str_contains($contents, 'TradeSkillMasterDB = {')) {
            throw new TsmParseException('That does not look like TradeSkillMaster.lua (no TradeSkillMasterDB table).');
        }

        $csv = [];
        $inventory = [];
        $hints = [];
        $itemStrings = [];
        $itemNames = [];

        $mode = null;
        $inItemInfo = false;
        $rows = 0;
        $lines = 0;
        $pos = 0;
        $len = strlen($contents);

        while ($pos < $len) {
            if (++$lines > $this->maxLines) {
                throw new TsmParseException('That file has too many lines to be a TradeSkillMaster data file.');
            }

            $nl = strpos($contents, "\n", $pos);
            $end = $nl === false ? $len : $nl;
            $line = substr($contents, $pos, $end - $pos);
            $pos = $end + 1;

            if ($line !== '' && $line[strlen($line) - 1] === "\r") {
                $line = substr($line, 0, -1);
            }

            if ($line === '') {
                continue;
            }

            if ($mode === 'inventory') {
                if ($line[0] === '}') {
                    $mode = null;
                } elseif (preg_match(self::INVENTORY_ENTRY, $line, $m)) {
                    $inventory[$m[1]] = ($inventory[$m[1]] ?? 0) + (int) $m[2];
                }

                continue;
            }

            if ($mode === 'itemStrings' || $mode === 'names') {
                if ($line === '},') {
                    $mode = null;
                } elseif ($line[0] === '"' && str_ends_with($line, '",')) {
                    $chunk = LuaString::unescape(substr($line, 1, -2));

                    if ($mode === 'itemStrings') {
                        $itemStrings[] = $chunk;
                    } else {
                        $itemNames[] = $chunk;
                    }
                }

                continue;
            }

            if ($line[0] !== '[' && $line[0] !== 'T') {
                continue;
            }

            if ($line === 'TSMItemInfoDB = {') {
                $inItemInfo = true;
            } elseif ($inItemInfo && $line === '["itemStrings"] = {') {
                $mode = 'itemStrings';
            } elseif ($inItemInfo && $line === '["names"] = {') {
                $mode = 'names';
            } elseif (preg_match(self::INVENTORY_HEADER, $line)) {
                $mode = 'inventory';
            } elseif (preg_match(self::CSV_HEADER, $line, $m)) {
                if (! str_ends_with($line, '",')) {
                    continue;
                }

                $text = LuaString::unescape(substr($line, strlen($m[0]), -2));

                foreach ($this->csvRows($m[1], $text) as $row) {
                    if (++$rows > $this->maxRows) {
                        throw new TsmParseException('That file has too many log entries to be a TradeSkillMaster data file.');
                    }

                    $csv[$m[2]][] = $row;
                }
            } elseif (preg_match(self::SALE_HINT, $line, $m)) {
                $hints[$m[2]] ??= $m[1];
            }
        }

        return new TsmData($this->names($itemStrings, $itemNames, $hints), $csv, $inventory);
    }

    /**
     * TSMItemInfoDB stores two parallel lists, each chunked into strings and joined with
     * \x02 (also within a chunk). Sale-hint keys fill any id the lists don't cover.
     *
     * @param  list<string>  $itemStrings
     * @param  list<string>  $itemNames
     * @param  array<string, string>  $hints
     * @return array<string, string>
     */
    private function names(array $itemStrings, array $itemNames, array $hints): array
    {
        $ids = $itemStrings === [] ? [] : explode("\x02", implode("\x02", $itemStrings));
        $labels = $itemNames === [] ? [] : explode("\x02", implode("\x02", $itemNames));

        $names = [];

        foreach ($ids as $i => $itemString) {
            if (! array_key_exists($i, $labels)) {
                break;
            }

            $names[ItemName::baseId($itemString)] = $labels[$i];
        }

        foreach ($hints as $id => $name) {
            $names[$id] ??= $name;
        }

        return $names;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    private function csvRows(string $realm, string $text): \Generator
    {
        $header = null;
        $count = 0;

        foreach (explode("\n", $text) as $i => $line) {
            if ($i === 0) {
                $header = explode(',', $line);
                $count = count($header);

                continue;
            }

            if ($line === '') {
                continue;
            }

            $fields = explode(',', $line);
            $row = ['realm' => $realm];

            for ($k = 0, $n = min($count, count($fields)); $k < $n; $k++) {
                $row[$header[$k]] = $fields[$k];
            }

            yield $row;
        }
    }
}
