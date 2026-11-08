<?php

namespace Tests\Support;

/**
 * Builds WowFlipLedger.lua (the addon's SavedVariables) the way the retail client writes it: CRLF
 * line endings, no indentation, one string per line, other sections the parser must skip.
 * `$legacy` writes the older style instead (LF, tabs, a `-- [n]` comment after each entry).
 */
final class AddonFile
{
    public const T0 = TsmFile::T0;

    /**
     * @param  list<string>  $events  records, e.g. "sale|1790000000|Area52|238371|50|2000000|100000"
     * @param  list<string>  $inv  "item:qty"
     * @param  list<string>  $unresolved  sales waiting on an item name, "sale|time|realm|name|qty|gross|cut"
     * @param  int|null  $interface  the client's interface number the addon stamps (120105 retail, 16001 Forever); null for files from before 0.2.1
     */
    public static function make(
        array $events = [],
        array $inv = [],
        ?int $last = self::T0 + 3600,
        bool $extras = true,
        array $unresolved = [],
        bool $legacy = false,
        ?int $interface = null,
    ): string {
        $i = $legacy ? "\t" : '';
        $list = static function (string $key, array $items) use ($i, $legacy): array {
            $out = ["{$i}[\"{$key}\"] = {"];

            foreach ($items as $n => $e) {
                $out[] = "{$i}{$i}\"{$e}\",".($legacy ? ' -- ['.($n + 1).']' : '');
            }

            $out[] = "{$i}},";

            return $out;
        };

        $out = ['WowFlipLedgerDB = {'];

        if ($extras) {
            // Per-character snapshots, as the addon writes them: nested tables to be skipped.
            array_push($out, "{$i}[\"chars\"] = {", "{$i}{$i}[\"55605c9a\"] = {", "{$i}{$i}{$i}[\"bags\"] = {", "{$i}{$i}{$i}{$i}[238371] = 50,", "{$i}{$i}{$i}},", "{$i}{$i}},", "{$i}},");
            array_push($out, "{$i}[\"seenOrder\"] = {", "{$i}{$i}\"sale|Wyrmfish|2|166000#2989693\",", "{$i}},");
        }

        array_push($out, ...$list('events', $events));

        if ($unresolved !== []) {
            array_push($out, ...$list('unresolved', $unresolved));
        }

        array_push($out, ...$list('inv', $inv));

        if ($last !== null) {
            $out[] = "{$i}[\"last\"] = {$last},";
        }

        if ($extras) {
            array_push($out, "{$i}[\"names\"] = {", "{$i}{$i}[\"Wyrmfish\"] = {", "{$i}{$i}{$i}238371,", "{$i}{$i}},", "{$i}},");
        }

        if ($interface !== null) {
            $out[] = "{$i}[\"interface\"] = {$interface},";
        }

        $out[] = "{$i}[\"schema\"] = 1,";
        $out[] = '}';

        return implode($legacy ? "\n" : "\r\n", $out).($legacy ? "\n" : "\r\n");
    }
}
