<?php

namespace App\Services;

use App\Models\Trade;
use App\Support\Bounds;
use App\Support\Holding;
use App\Support\ItemName;

/**
 * Weighted-average cost ledger. Port of the legacy build_ledger, same integer math.
 *
 * Buys come from the user's own trades. Sales come from TSM csvSales and only count from an
 * item's first tracked buy onward. A sale larger than the tracked held quantity is "own
 * stock" (gathered or pre-existing) and is reported apart from flip P/L.
 *
 * Buys sort before sells at the same timestamp. All operands are non-negative, so intdiv
 * matches Python's floor division.
 *
 * @phpstan-type Position array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}
 */
class LedgerService
{
    // Replay order of events at one time: buys, then craft use and output, sales, corrections, deposits.
    private const BUY = 0;

    private const CRAFT_OUT = 1;

    private const CRAFT_IN = 2;

    private const SALE = 3;

    private const CORRECTION = 4;

    /** Event side of a lost deposit: moves realized P/L only, after every sale and correction at its time. */
    public const DEPOSIT = 99;

    /**
     * From TSM csvSales rows, as parsed (string fields). Malformed rows are skipped.
     *
     * @param  list<array{ts: int, item: string, qty: int, cost: int}>  $trades  cost is the lot total in copper
     * @param  list<array<string, string>>  $sales  csvSales rows; price is per unit after the AH cut
     * @return array<string, array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}>
     *                                                                                                                     keyed by base item id. bq/bc bought qty/cost, sq/sr sold qty/net revenue, pnl realized,
     *                                                                                                                     oq/orev own-stock qty/revenue, inv/cost still held qty/cost basis.
     */
    public function build(array $trades, array $sales): array
    {
        $normalized = [];

        foreach ($sales as $r) {
            // Same limits as ingest, so qty x price stays an int.
            if (($r['source'] ?? null) !== TsmData::SOURCE_AUCTION || Bounds::count($r['quantity'] ?? null, Bounds::MAX_QTY) === null
                || Bounds::count($r['price'] ?? null, Bounds::MAX_PRICE) === null || Bounds::count($r['time'] ?? null) === null) {
                continue;
            }

            $normalized[] = [
                'item' => ItemName::baseId($r['itemString'] ?? ''),
                'ts' => (int) $r['time'],
                'qty' => (int) $r['quantity'],
                'unit_price' => (int) $r['price'],
            ];
        }

        return $this->buildFromSales($trades, $normalized);
    }

    /**
     * From already-validated auction sales, as stored in tsm_sales.
     *
     * Item ids are only used as array keys, so any int or string id works (the app passes ints).
     *
     * A trade with `correction` set (a Trade::CORRECTIONS side) removes qty units at the average
     * cost with no revenue: with no P/L for `adjust` (stock the log counted but the user never
     * held), or as a realized loss for `writeoff` (stock used or lost). `vendor` also carries the
     * gold received in `cost` and books proceeds minus the removed cost. It counts only
     * from the item's first buy onward, like a sale, and sorts after buys and sales at the same time.
     *
     * @param  list<array{ts: int, item: int|string, qty: int, cost: int, correction?: string}>  $trades
     * @param  iterable<array{item: int|string, ts: int, qty: int, unit_price: int}>  $sales  unit_price after the AH cut; may be a generator
     * @param  (callable(int, int): void)|null  $onPnl  called with (ts, copper) for every sale, write-off or lost deposit that moves realized P/L
     * @param  iterable<array{item: int|string, ts: int, lost: int}>  $deposits  deposit forfeited on an expired or cancelled auction: a loss, no stock change; counts only from the item's first buy, like a sale
     * @param  list<array{ts: int, item: int|string, qty: int, craft: int, kind: 'in'|'out'}>  $crafts  one craft's rows share `craft`: `out` rows use qty units of an ingredient at its average cost (no P/L), the `in` row adds the product at the summed cost. The product counts from its first `in` row, like a first buy; an ingredient used before its first buy is ignored.
     * @return array<int|string, array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}>
     */
    public function buildFromSales(array $trades, iterable $sales, ?callable $onPnl = null, iterable $deposits = [], array $crafts = [], ?array &$craftCosts = null): array
    {
        $onPnl ??= static function (): void {};
        $flat = [];
        $first = [];
        $corrections = [];

        foreach ($trades as $t) {
            if (isset($t['correction'])) {
                $corrections[] = $t;

                continue;
            }

            $flat[] = [$t['ts'], self::BUY, $t['qty'], $t['cost'], 0, $t['item']];
            $first[$t['item']] = min($first[$t['item']] ?? PHP_INT_MAX, $t['ts']);
        }

        // What a craft makes counts as the product's first buy: its later sales are tracked.
        foreach ($crafts as $c) {
            if ($c['kind'] === 'in') {
                $first[$c['item']] = min($first[$c['item']] ?? PHP_INT_MAX, $c['ts']);
            }
        }

        foreach ($crafts as $c) {
            if (isset($first[$c['item']]) && $c['ts'] >= $first[$c['item']]) {
                $flat[] = [$c['ts'], $c['kind'] === 'in' ? self::CRAFT_IN : self::CRAFT_OUT, $c['qty'], 0, $c['craft'], $c['item']];
            }
        }

        foreach ($corrections as $t) {
            if (isset($first[$t['item']]) && $t['ts'] >= $first[$t['item']]) {
                $flat[] = [$t['ts'], self::CORRECTION + Trade::correctionRank($t['correction']), $t['qty'], Trade::proceeds($t['correction'], $t['cost']) ?? 0, 0, $t['item']];
            }
        }

        foreach ($sales as $s) {
            $k = $s['item'];

            if ($s['qty'] <= 0 || ! isset($first[$k]) || $s['ts'] < $first[$k]) {
                continue;
            }

            $flat[] = [$s['ts'], self::SALE, $s['qty'], $s['qty'] * $s['unit_price'], 0, $k];
        }

        foreach ($deposits as $d) {
            if ($d['lost'] > 0 && isset($first[$d['item']]) && $d['ts'] >= $first[$d['item']]) {
                $flat[] = [$d['ts'], self::DEPOSIT, 0, $d['lost'], 0, $d['item']];
            }
        }

        // One pass over every item's events by time, so a craft sees its ingredients' cost at that moment.
        usort($flat, static fn (array $a, array $b): int => $a <=> $b);

        return $this->replay($flat, array_keys($first), $onPnl, $craftCosts);
    }

    /**
     * @param  list<array{int, int, int, int, int, int|string}>  $events  [ts, order, qty, total copper (a sale's net, a correction's proceeds, a deposit's loss), craft id, item]
     * @param  list<int|string>  $items  in the order the output lists them
     * @param  callable(int, int): void  $onPnl
     * @return array<int|string, array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}>
     */
    private function replay(array $events, array $items, callable $onPnl, ?array &$crafted = null): array
    {
        $acc = [];
        $hold = [];

        foreach ($items as $k) {
            $acc[$k] = ['bq' => 0, 'bc' => 0, 'sq' => 0, 'sr' => 0, 'pnl' => 0, 'oq' => 0, 'orev' => 0];
            $hold[$k] = new Holding;
        }

        $crafted = [];

        foreach ($events as [$ts, $side, $q, $c, $craft, $k]) {
            $a = &$acc[$k];
            $h = $hold[$k];

            if ($side === self::BUY) {
                $h->buy($q, $c);
                $a['bq'] += $q;
                $a['bc'] += $c;
            } elseif ($side === self::CRAFT_OUT) {
                $crafted[$craft] = ($crafted[$craft] ?? 0) + $h->take($q)[1];
            } elseif ($side === self::CRAFT_IN) {
                $cost = $crafted[$craft] ?? 0;
                $h->buy($q, $cost);
                $a['bq'] += $q;
                $a['bc'] += $cost;
            } elseif ($side === self::DEPOSIT) {
                $a['pnl'] -= $c;
                $onPnl($ts, -$c);
            } elseif ($side >= self::CORRECTION) {
                $gain = $h->correct(Trade::CORRECTIONS[$side - self::CORRECTION], $q, $c);
                $a['pnl'] += $gain ?? 0;
                $gain && $onPnl($ts, $gain);
            } else {
                [$m, $rev, $cogs] = $h->sell($q, $c);

                $a['pnl'] += $rev - $cogs;
                $rev !== $cogs && $onPnl($ts, $rev - $cogs);

                $a['sq'] += $m;
                $a['sr'] += $rev;
                $a['oq'] += $q - $m;
                $a['orev'] += $c - $rev;
            }

            unset($a);
        }

        foreach ($acc as $k => $a) {
            $acc[$k] = $a + ['inv' => $hold[$k]->held, 'cost' => $hold[$k]->cost];
        }

        return $acc;
    }
}
