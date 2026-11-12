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
     * @param  (callable(int, int): void)|null  $onPnl  called with (ts, copper) for every sale or write-off that moves realized P/L
     * @return array<int|string, array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}>
     */
    public function buildFromSales(array $trades, iterable $sales, ?callable $onPnl = null): array
    {
        $events = [];
        $corrections = [];

        foreach ($trades as $t) {
            if (isset($t['correction'])) {
                $corrections[] = $t;

                continue;
            }

            $events[$t['item']][] = [$t['ts'], 0, $t['qty'], $t['cost']];
        }

        $first = array_map(static fn (array $e): int => min(array_column($e, 0)), $events);

        foreach ($corrections as $t) {
            if (isset($first[$t['item']]) && $t['ts'] >= $first[$t['item']]) {
                $events[$t['item']][] = [$t['ts'], 2 + Trade::correctionRank($t['correction']), $t['qty'], Trade::proceeds($t['correction'], $t['cost']) ?? 0];
            }
        }

        foreach ($sales as $s) {
            $k = $s['item'];

            if ($s['qty'] <= 0 || ! isset($first[$k]) || $s['ts'] < $first[$k]) {
                continue;
            }

            $events[$k][] = [$s['ts'], 1, $s['qty'], $s['qty'] * $s['unit_price']];
        }

        $out = [];

        foreach ($events as $k => $list) {
            usort($list, static fn (array $a, array $b): int => $a <=> $b);
            $out[$k] = $this->replay($list, $onPnl ?? static function (): void {});
        }

        return $out;
    }

    /**
     * @param  list<array{int, int, int, int}>  $events  [ts, side (0 buy, 1 sell, 2 + Trade::correctionRank()), qty, total copper (a correction's proceeds)]
     * @param  callable(int, int): void  $onPnl
     * @return array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}
     */
    private function replay(array $events, callable $onPnl): array
    {
        $a = ['bq' => 0, 'bc' => 0, 'sq' => 0, 'sr' => 0, 'pnl' => 0, 'oq' => 0, 'orev' => 0];
        $h = new Holding;

        foreach ($events as [$ts, $side, $q, $c]) {
            if ($side === 0) {
                $h->buy($q, $c);
                $a['bq'] += $q;
                $a['bc'] += $c;

                continue;
            }

            if ($side >= 2) {
                $gain = $h->correct(Trade::CORRECTIONS[$side - 2], $q, $c);
                $a['pnl'] += $gain ?? 0;
                $gain && $onPnl($ts, $gain);

                continue;
            }

            [$m, $rev, $cogs] = $h->sell($q, $c);

            $a['pnl'] += $rev - $cogs;
            $rev !== $cogs && $onPnl($ts, $rev - $cogs);

            $a['sq'] += $m;
            $a['sr'] += $rev;
            $a['oq'] += $q - $m;
            $a['orev'] += $c - $rev;
        }

        return $a + ['inv' => $h->held, 'cost' => $h->cost];
    }
}
