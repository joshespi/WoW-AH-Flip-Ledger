<?php

namespace Tests\Support;

/**
 * Builds small TradeSkillMaster.lua files for edge-case tests, in TSM's own layout (one entry
 * per line, newlines escaped, \x02 raw between names).
 */
final class TsmFile
{
    public const T0 = 1_790_000_000;

    /**
     * @param  list<array{string, int, int, int, string}>  $sales  [itemString, qty, unit price, time, source]
     * @param  list<array{string, int, int}>  $expired  [itemString, qty, time]
     * @param  list<array{string, int, int, int, string}>  $buys  [itemString, qty, unit price, time, source]
     * @param  array<string, int>  $inventory  item key (as TSM writes it) => qty
     * @param  array<string, string>  $names  itemString => name
     */
    public static function make(
        array $sales = [],
        array $expired = [],
        array $buys = [],
        array $inventory = [],
        array $names = [],
        string $realm = 'TestRealm',
    ): string {
        $out = ['', 'TradeSkillMasterDB = {'];

        $salesHeader = 'itemString,stackSize,quantity,price,otherPlayer,player,time,source';
        $text = $salesHeader;

        foreach ($sales as [$item, $qty, $price, $time, $source]) {
            $text .= "\n{$item},{$qty},{$qty},{$price},Multiple Buyers,Alt1,{$time},{$source}";
        }

        $out[] = '["r@'.$realm.'@internalData@csvSales"] = "'.self::esc($text).'",';

        $text = 'itemString,stackSize,quantity,player,time';

        foreach ($expired as [$item, $qty, $time]) {
            $text .= "\n{$item},{$qty},{$qty},Alt1,{$time}";
        }

        $out[] = '["r@'.$realm.'@internalData@csvExpired"] = "'.self::esc($text).'",';

        $text = $salesHeader;

        foreach ($buys as [$item, $qty, $price, $time, $source]) {
            $text .= "\n{$item},{$qty},{$qty},{$price},Multiple Sellers,Alt1,{$time},{$source}";
        }

        $out[] = '["r@'.$realm.'@internalData@csvBuys"] = "'.self::esc($text).'",';

        if ($inventory !== []) {
            $out[] = '["s@Alt1 - Horde - '.$realm.'@internalData@bagQuantity"] = {';

            foreach ($inventory as $key => $qty) {
                $out[] = '["'.$key.'"] = '.$qty.',';
            }

            $out[] = '},';
        }

        $out[] = '}';

        if ($names !== []) {
            $out[] = 'TSMItemInfoDB = {';
            $out[] = '["itemStrings"] = {';
            $out[] = '"'.self::esc(implode("\x02", array_keys($names))).'",';
            $out[] = '},';
            $out[] = '["names"] = {';
            $out[] = '"'.self::esc(implode("\x02", array_values($names))).'",';
            $out[] = '},';
            $out[] = '}';
        }

        return implode("\n", $out)."\n";
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $s);
    }
}
