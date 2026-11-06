<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\MarketStats;
use App\Support\Money;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use SplMinHeap;

/**
 * Builds a user's portfolio: the weighted-average ledger (their trades plus their TSM sales)
 * joined with the shared market numbers in item_stats.
 *
 * Exact numbers (floor, cost, P/L) are always shown. Derived ones (recent, typical, 60d hist,
 * sold/day, and the chips and days-to-sell built on them) only appear once an item has
 * `ledger.prices.min_days` days of snapshot history; before that the row says "collecting data".
 *
 * Item ids stay ints here; the ledger only uses them as array keys.
 */
class PositionsService
{
    public function __construct(private LedgerService $ledger) {}

    public function forUser(User $user, string $game = Game::RETAIL): Portfolio
    {
        [$trades, $adjustments] = $this->trades($user, $game);

        if ($trades === []) {
            return new Portfolio([], $this->summary([], $game), null, []);
        }

        $firstBuy = [];

        foreach ($trades as $t) {
            $firstBuy[$t['item']] = min($firstBuy[$t['item']] ?? PHP_INT_MAX, $t['ts']);
        }

        $ids = array_keys($firstBuy);
        $newest = new SplMinHeap;
        $events = [];
        $ledger = $this->ledger->buildFromSales([...$trades, ...$adjustments], $this->sales($user, $game, $firstBuy, $newest),
            function (int $ts, int $pnl) use (&$events): void {
                $events[] = [$ts, $pnl];
            });

        $names = $this->names($ids);
        $inventory = DB::table('inventory')->ownedBy($user)->inGame($game)->pluck('qty', 'item_id')->all();
        $hasSnapshot = $inventory !== [];
        $stats = MarketStats::forItems($user->region, $game, $ids);

        $rows = [];

        foreach ($ledger as $id => $a) {
            $rows[] = $this->row($id, $a, $names[$id], $stats[$id] ?? null, $user, $hasSnapshot ? (int) ($inventory[$id] ?? 0) : null);
        }

        usort($rows, fn (PositionRow $a, PositionRow $b) => [$a->closed(), $b->costBasis, $b->realized, $a->name]
            <=> [$b->closed(), $a->costBasis, $a->realized, $b->name]);

        $summary = $this->summary($rows, $game);

        return new Portfolio($rows, $summary, $this->concentration($rows, $summary['stock_cost']), $this->recent($trades, $newest, $names), $events);
    }

    /** Units of one item the ledger says the user holds, by the same rule as forUser(). */
    public function held(User $user, int $itemId, string $game = Game::RETAIL): int
    {
        [$trades, $adjustments] = $this->trades($user, $game, $itemId);

        if ($trades === []) {
            return 0;
        }

        $first = min(array_column($trades, 'ts'));
        $ledger = $this->ledger->buildFromSales([...$trades, ...$adjustments], $this->sales($user, $game, [$itemId => $first], new SplMinHeap));

        return $ledger[$itemId]['inv'];
    }

    /**
     * One item's count in the latest TSM inventory snapshot, by the same rule as forUser(): an
     * item missing from a snapshot that exists is 0; null when no upload carried a snapshot.
     */
    public function tsmStock(User $user, int $itemId, string $game = Game::RETAIL): ?int
    {
        $qty = DB::table('inventory')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->value('qty');

        return $qty !== null ? (int) $qty : (DB::table('inventory')->ownedBy($user)->inGame($game)->exists() ? 0 : null);
    }

    /**
     * @param  array{bq: int, bc: int, sq: int, sr: int, pnl: int, oq: int, orev: int, inv: int, cost: int}  $a
     */
    private function row(int $id, array $a, string $name, ?object $stat, User $user, ?int $inStock): PositionRow
    {
        $cut = Money::NET;
        $held = $a['inv'];

        $avg = $held > 0 ? $a['cost'] / $held : null;
        $breakEven = $avg === null ? null : Money::sellPrice($avg);
        $targetSell = $avg === null ? null : Money::sellPrice($avg, $user->target_margin_pct);

        // The floor (and everything valued at it) only means something for stock still held.
        $floor = $held > 0 && $stat !== null ? (int) $stat->floor : null;
        $days = $stat !== null ? (int) $stat->days_of_data : 0;
        $ready = MarketStats::ready($stat);
        $derived = fn (string $column) => MarketStats::derived($stat, $column);

        $typical = $derived('typical');
        $sold = $derived('sold_per_day_est');

        $priced = $floor > 0;
        $value = $priced ? Money::round($held * $floor * $cut) : null;
        $vsTypical = $priced && $typical > 0 ? $floor / $typical : null;
        $share = $user->market_share_pct / 100;

        return new PositionRow(
            itemId: $id,
            name: $name,
            held: $held,
            avgCost: $avg,
            breakEven: $breakEven,
            targetSell: $targetSell,
            soldQty: $a['sq'],
            realized: $a['pnl'],
            ownRevenue: $a['orev'],
            costBasis: $a['cost'],
            floor: $floor,
            recent: $derived('recent'),
            typical: $typical,
            hist60: $derived('hist60'),
            soldPerDay: $sold,
            daysOfData: $days,
            statsReady: $ready,
            // A free lot (cost 0) has no break-even to compare against.
            floorVsBreakEven: $priced && $breakEven > 0 ? $floor / $breakEven - 1 : null,
            floorVsTypical: $vsTypical,
            valueAtFloor: $value,
            unrealized: $value === null ? null : $value - $a['cost'],
            daysToSell: $held > 0 && $sold > 0 && $share > 0 ? $held / ($sold * $share) : null,
            status: $priced ? ($floor * $cut > $avg ? 'profit' : 'underwater') : null,
            market: match (true) {
                $vsTypical === null => null,
                $vsTypical <= config('ledger.positions.below_ratio') => 'below',
                $vsTypical >= config('ledger.positions.above_ratio') => 'above',
                default => null,
            },
            unaccounted: $inStock !== null && $held > $inStock ? $held - $inStock : null,
        );
    }

    /**
     * Floor value and unrealized P/L are null for a game with no price feed: there is no floor.
     *
     * @param  list<PositionRow>  $rows
     * @return array{realized: int, stock_cost: int, stock_at_floor: ?int, unrealized: ?int, own_stock_sales: int, unpriced_items: int}
     */
    private function summary(array $rows, string $game): array
    {
        $s = ['realized' => 0, 'stock_cost' => 0, 'stock_at_floor' => 0, 'unrealized' => 0, 'own_stock_sales' => 0, 'unpriced_items' => 0];

        foreach ($rows as $r) {
            $s['realized'] += $r->realized;
            $s['stock_cost'] += $r->costBasis;
            $s['own_stock_sales'] += $r->ownRevenue;

            if ($r->valueAtFloor !== null) {
                $s['stock_at_floor'] += $r->valueAtFloor;
                $s['unrealized'] += $r->unrealized;
            } elseif ($r->held > 0) {
                $s['unpriced_items']++;
            }
        }

        if (! Game::hasMarket($game)) {
            $s['stock_at_floor'] = $s['unrealized'] = null;
        }

        return $s;
    }

    /**
     * @param  list<PositionRow>  $rows
     * @return array{name: string, pct: float}|null
     */
    private function concentration(array $rows, int $stockCost): ?array
    {
        if ($stockCost <= 0) {
            return null;
        }

        $top = array_reduce($rows, fn (?PositionRow $top, PositionRow $r) => $top === null || $r->costBasis > $top->costBasis ? $r : $top);
        $pct = $top->costBasis / $stockCost * 100;

        return $pct > config('ledger.positions.concentration_pct') ? ['name' => $top->name, 'pct' => $pct] : null;
    }

    /**
     * Last buys and ledger-counted sales, newest first.
     *
     * @param  list<array{ts: int, item: int, qty: int, cost: int}>  $trades
     * @param  SplMinHeap<array{int, int, int, int}>  $newestSales  [ts, item, qty, unit], filled by sales()
     * @param  array<int, string>  $names
     * @return list<array{type: string, ts: int, item_id: int, name: string, qty: int, unit: int, total: int}>
     */
    private function recent(array $trades, SplMinHeap $newestSales, array $names): array
    {
        $events = [];

        foreach ($trades as $t) {
            $events[] = ['type' => 'buy', 'ts' => $t['ts'], 'item_id' => $t['item'], 'name' => $names[$t['item']],
                'qty' => $t['qty'], 'unit' => intdiv($t['cost'], max(1, $t['qty'])), 'total' => $t['cost']];
        }

        foreach ($newestSales as [$ts, $id, $qty, $unit]) {
            $events[] = ['type' => 'sale', 'ts' => $ts, 'item_id' => $id, 'name' => $names[$id], 'qty' => $qty, 'unit' => $unit, 'total' => $qty * $unit];
        }

        usort($events, fn (array $a, array $b) => $b['ts'] <=> $a['ts']);

        return array_slice($events, 0, (int) config('ledger.positions.recent_activity'));
    }

    /**
     * The user's buys, and their stock corrections (units removed at average cost, as a loss for
     * a write-off, see LedgerService), from one query. All items, or just $itemId.
     *
     * @return array{0: list<array{ts: int, item: int, qty: int, cost: int}>, 1: list<array{ts: int, item: int, qty: int, cost: int, correction: string}>}
     */
    private function trades(User $user, string $game, ?int $itemId = null): array
    {
        $buys = [];
        $adjustments = [];

        foreach (DB::table('trades')->ownedBy($user)->inGame($game)->whereIn('side', [Trade::SIDE_BUY, ...Trade::CORRECTIONS])
            ->when($itemId !== null, fn ($q) => $q->where('item_id', $itemId))
            ->get(['side', 'item_id', 'traded_at', 'qty', 'cost_copper']) as $r) {
            $row = ['ts' => Utc::ts($r->traded_at), 'item' => (int) $r->item_id, 'qty' => (int) $r->qty];

            if ($r->side !== Trade::SIDE_BUY) {
                $adjustments[] = $row + ['cost' => 0, 'correction' => $r->side];
            } else {
                $buys[] = $row + ['cost' => (int) $r->cost_copper];
            }
        }

        return [$buys, $adjustments];
    }

    /**
     * Streams the user's auction sales of traded items into the ledger. On the way through it
     * keeps the newest ledger-counted ones (on or after the item's first buy, the ledger's own
     * rule) in $newest, so recent activity needs no second query.
     *
     * @param  array<int, int>  $firstBuy  item id => first buy timestamp
     * @param  SplMinHeap<array{int, int, int, int}>  $newest
     * @return \Generator<int, array{item: int, ts: int, qty: int, unit_price: int}>
     */
    private function sales(User $user, string $game, array $firstBuy, SplMinHeap $newest): \Generator
    {
        $keep = (int) config('ledger.positions.recent_activity');
        $rows = DB::table('tsm_sales')->ownedBy($user)->inGame($game)->where('source', TsmData::SOURCE_AUCTION)
            ->whereIn('item_id', array_keys($firstBuy))->select('item_id', 'qty', 'unit_price_copper', 'sold_at')->cursor();

        foreach ($rows as $r) {
            $sale = ['item' => (int) $r->item_id, 'ts' => Utc::ts($r->sold_at), 'qty' => (int) $r->qty, 'unit_price' => (int) $r->unit_price_copper];

            if ($sale['ts'] >= $firstBuy[$sale['item']]) {
                $newest->insert([$sale['ts'], $sale['item'], $sale['qty'], $sale['unit_price']]);

                if ($newest->count() > $keep) {
                    $newest->extract();
                }
            }

            yield $sale;
        }
    }

    /**
     * Display names; two items sharing a name (rank variants) get their id appended so the rows
     * stay distinguishable.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $raw = DB::table('items')->whereIn('id', $ids)->pluck('name', 'id');
        $names = [];

        foreach ($ids as $id) {
            $names[$id] = ItemName::display($raw[$id] ?? null, $id);
        }

        $dupes = array_filter(array_count_values($names), fn (int $n) => $n > 1);

        foreach ($names as $id => $name) {
            if (isset($dupes[$name])) {
                $names[$id] = $name.' #'.$id;
            }
        }

        return $names;
    }
}
