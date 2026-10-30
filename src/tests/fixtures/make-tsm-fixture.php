<?php

/**
 * Builds the anonymized TSM fixture from a real SavedVariables file. Run once, by hand:
 *
 *   php tests/fixtures/make-tsm-fixture.php <TradeSkillMaster.lua> <trades.csv> <watchlist.txt> <outdir> [until]
 *
 * The checked-in fixture was built with until = 2026-10-25T12:13:23-06:00, the save the plan's
 * known answers come from. Inventory is whatever the source file held, not time-filtered.
 *
 * Keeps only the items named in trades.csv and watchlist.txt, puts every log row under one
 * realm, and replaces character names and counterparties. Prices, quantities and times are
 * left alone because the known-answer tests depend on them. Output is TSM's own layout:
 * one entry per line, newlines escaped, \x01 and \x02 raw.
 */

use App\Services\TsmParser;
use App\Support\ItemName;

require __DIR__.'/../../vendor/autoload.php';

if ($argc < 5 || $argc > 6) {
    fwrite(STDERR, "usage: php make-tsm-fixture.php <TradeSkillMaster.lua> <trades.csv> <watchlist.txt> <outdir> [until]\n");
    exit(1);
}

[, $svPath, $tradesPath, $watchPath, $outDir] = $argv;

// Optional cutoff (anything strtotime reads): the live file keeps moving, so pin the fixture
// to the moment the known answers were taken. Log rows after it are dropped.
$until = isset($argv[5]) ? strtotime($argv[5]) : PHP_INT_MAX;

$raw = file_get_contents($svPath);
$data = (new TsmParser)->parse($raw);

$wanted = [];

foreach (array_slice(file($tradesPath, FILE_IGNORE_NEW_LINES), 1) as $line) {
    $f = str_getcsv($line, ',', '"', '');
    if (isset($f[1]) && str_starts_with($f[1], 'i:')) {
        $wanted[ItemName::baseId($f[1])] = true;
    }
}

$byName = [];
foreach ($data->names as $id => $name) {
    $byName[strtolower($name)][] = $id;
}

foreach (file($watchPath, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (str_starts_with($line, 'i:')) {
        $wanted[ItemName::baseId($line)] = true;
    }
    foreach ($byName[strtolower($line)] ?? [] as $id) {
        $wanted[$id] = true;
    }
}

function esc(string $s): string
{
    return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $s);
}

function anonymize(string $who): string
{
    return match (true) {
        in_array($who, ['Merchant', 'Multiple Buyers', 'Multiple Sellers', ''], true) => $who,
        default => 'Counterparty',
    };
}

$headers = [
    'csvSales' => ['itemString', 'stackSize', 'quantity', 'price', 'otherPlayer', 'player', 'time', 'source'],
    'csvBuys' => ['itemString', 'stackSize', 'quantity', 'price', 'otherPlayer', 'player', 'time', 'source'],
    'csvExpired' => ['itemString', 'stackSize', 'quantity', 'player', 'time'],
    'csvCancelled' => ['itemString', 'stackSize', 'quantity', 'player', 'time'],
];

$out = ['', 'TradeSkillMasterDB = {'];

foreach ($headers as $kind => $cols) {
    $text = implode(',', $cols);
    $n = 0;

    foreach ($data->rows($kind) as $row) {
        if (! isset($row['itemString']) || ! isset($wanted[ItemName::baseId($row['itemString'])])) {
            continue;
        }
        if ((int) ($row['time'] ?? 0) > $until) {
            continue;
        }
        foreach (['otherPlayer', 'player'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = $k === 'player' ? 'Alt1' : anonymize($row[$k]);
            }
        }
        $text .= "\n".implode(',', array_map(fn ($c) => $row[$c] ?? '', $cols));
        $n++;
    }

    $out[] = '["r@TestRealm@internalData@'.$kind.'"] = "'.esc($text).'",';
    $out[] = '["r@OtherRealm@internalData@'.$kind.'"] = "'.esc(implode(',', $cols)).'",';
    fwrite(STDERR, sprintf("%-13s %d rows\n", $kind, $n));
}

$out[] = '["r@TestRealm@internalData@csvIncome"] = "type,amount,otherPlayer,player,time",';
$out[] = '["r@TestRealm@internalData@csvExpense"] = "type,amount,otherPlayer,player,time\nRepair Bill,98,Merchant,Alt1,1787448791",';
$out[] = '["r@internalData@csvSales"] = 10,';
$out[] = '["f@Horde - TestRealm@internalData@mats"] = {';
$out[] = '},';

// inventory: own scanner, keeps original (variant) keys so the parser's base-id summing is exercised
$owners = [];
$lines = explode("\n", str_replace("\r\n", "\n", $raw));
$tables = [];

for ($i = 0; $i < count($lines); $i++) {
    if (! preg_match('/^\["([sg])@([^"]*)@internalData@(bag|bank|reagentBank|auction|mail|warbank)Quantity"\] = \{$/', $lines[$i], $m)) {
        continue;
    }
    $entries = [];
    for ($j = $i + 1; $j < count($lines) && $lines[$j][0] !== '}'; $j++) {
        if (preg_match('/^\["([^"]+)"\] = (\d+),?$/', $lines[$j], $e) && isset($wanted[ItemName::baseId($e[1])])) {
            $entries[$e[1]] = $e[2];
        }
    }
    if ($entries !== []) {
        $tables[] = [$m[1], $m[2], $m[3], $entries];
    }
}

foreach ($tables as [$scope, $owner, $loc, $entries]) {
    if ($scope === 'g') {
        $key = 'g@'.$owner.'@internalData@'.$loc.'Quantity';
    } else {
        $owners[$owner] ??= 'Alt'.(count($owners) + 1).' - Horde - TestRealm';
        $key = 's@'.$owners[$owner].'@internalData@'.$loc.'Quantity';
    }
    $out[] = '["'.$key.'"] = {';
    foreach ($entries as $k => $q) {
        $out[] = '["'.$k.'"] = '.$q.',';
    }
    $out[] = '},';
}

$out[] = '["s@Alt1 - Horde - TestRealm@internalData@mailQuantity"] = {';
$out[] = '},';

// sale hints: "Name\x01i:123\x01price" = timestamp
$hintTime = 1792282309;
foreach (array_keys($wanted) as $id) {
    if (isset($data->names[$id]) && str_starts_with($id, 'i:')) {
        $out[] = '["'.esc($data->names[$id])."\x01".$id."\x01".'1000"] = '.$hintTime++.',';
    }
}

$out[] = '}';

// TSMItemInfoDB: two parallel lists, chunked, \x02 inside and between chunks
$ids = [];
$names = [];

foreach (array_keys($wanted) as $id) {
    if (isset($data->names[$id])) {
        $ids[] = $id;
        $names[] = $data->names[$id];
    }
}

// decoys: a bonus-id variant of the same item, a pet, a quoted name, a non-ASCII name
$first = $ids[0];
$ids = array_merge($ids, [$first.'::1:6710', 'p:2843', 'i:999001', 'i:999002']);
$names = array_merge($names, [$names[0], 'Fixture Pet', 'The "Quoted" Thing', 'Café Crème']);

$chunk = fn (array $xs): array => array_map(
    fn ($c) => '"'.esc(implode("\x02", $c)).'",',
    array_chunk($xs, 25),
);

$out[] = 'TSMItemInfoDB = {';
$out[] = '["versionStr"] = "19,enUS,12.1.5,70575",';
$out[] = '["itemStrings"] = {';
array_push($out, ...$chunk($ids));
$out[] = '},';
$out[] = '["data"] = "AAAAAAAAAAAAAAAA",';
$out[] = '["names"] = {';
array_push($out, ...$chunk($names));
$out[] = '},';
$out[] = '}';

@mkdir($outDir, 0777, true);
file_put_contents($outDir.'/TradeSkillMaster.lua', implode("\n", $out)."\n");
copy($tradesPath, $outDir.'/trades.csv');
copy($watchPath, $outDir.'/watchlist.txt');

fwrite(STDERR, sprintf("items kept: %d, inventory tables: %d, bytes: %d\n", count($ids), count($tables), filesize($outDir.'/TradeSkillMaster.lua')));
