<?php

namespace App\Services;

use App\Models\Trade;
use App\Models\User;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\MarketStats;
use App\Support\Num;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/**
 * Everything the item page shows for one commodity: the user's own timeline (buys, sales,
 * expiries, cancellations), the running position after each buy and sale, the market numbers
 * and the daily price series. The position replay is the ledger's own rule (weighted average
 * cost, buys before sells at the same instant, sales before the first buy ignored), kept in
 * step by a test against LedgerService.
 */
class ItemDetailService
{
    public const TIMELINE_ROWS = 100;

    public const CHART_DAYS = 90;

    /** Per-table cap on rows read, so a pathological history cannot exhaust memory. */
    private const MAX_EVENTS = 5000;

    /**
     * Null when the user has no history with the item and no prices exist for it either.
     *
     * @return ?array{
     *     id: int, name: string,
     *     timeline: list<array<string, mixed>>, timeline_total: int,
     *     position: array{held: int, avg: ?float, realized: int, own_qty: int},
     *     steps: list<array{ts: int, held: int, avg: ?float}>,
     *     buys: list<array{ts: int, unit: int, qty: int}>, sales: list<array{ts: int, unit: int, qty: int}>,
     *     market: ?array<string, mixed>, daily: list<array{ts: int, median: int, min: int}>
     * }
     */
    public function forUser(User $user, int $itemId, string $game = Game::RETAIL): ?array
    {
        $trades = DB::table('trades')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->where('side', Trade::SIDE_BUY)
            ->orderBy('traded_at')->orderBy('id')->limit(self::MAX_EVENTS)->get(['traded_at', 'qty', 'cost_copper']);
        $corrections = DB::table('trades')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->whereIn('side', Trade::CORRECTIONS)
            ->orderBy('traded_at')->orderBy('id')->limit(self::MAX_EVENTS)->get(['side', 'traded_at', 'qty']);
        $sales = DB::table('tsm_sales')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->where('source', TsmData::SOURCE_AUCTION)
            ->orderBy('sold_at')->orderBy('id')->limit(self::MAX_EVENTS)->get(['sold_at', 'qty', 'unit_price_copper']);
        $expired = DB::table('tsm_expired')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->orderBy('at')->limit(self::MAX_EVENTS)->get(['at', 'qty']);
        $cancelled = DB::table('tsm_cancelled')->ownedBy($user)->inGame($game)->where('item_id', $itemId)->orderBy('at')->limit(self::MAX_EVENTS)->get(['at', 'qty']);

        $stat = MarketStats::forItems($user->region, $game, [$itemId])[$itemId] ?? null;
        $daily = ! Game::hasMarket($game) ? [] : $this->daily($user->region, $itemId, $stat);
        $name = DB::table('items')->where('id', $itemId)->value('name');

        if ($trades->isEmpty() && $sales->isEmpty() && $expired->isEmpty() && $cancelled->isEmpty() && $stat === null && $daily === [] && $name === null) {
            return null;
        }

        $events = [];

        foreach ($trades as $t) {
            $events[] = ['ts' => Utc::ts($t->traded_at), 'order' => 0, 'kind' => 'buy', 'qty' => (int) $t->qty, 'total' => (int) $t->cost_copper];
        }

        // Kind is the side ('adjust' or 'writeoff'); same order as the ledger's.
        foreach ($corrections as $t) {
            $events[] = ['ts' => Utc::ts($t->traded_at), 'order' => $t->side === Trade::SIDE_WRITEOFF ? 4 : 3, 'kind' => $t->side, 'qty' => (int) $t->qty, 'total' => null];
        }

        foreach ($sales as $s) {
            $events[] = ['ts' => Utc::ts($s->sold_at), 'order' => 1, 'kind' => 'sale', 'qty' => (int) $s->qty, 'total' => (int) $s->qty * (int) $s->unit_price_copper];
        }

        foreach ([['expired', $expired], ['cancelled', $cancelled]] as [$kind, $rows]) {
            foreach ($rows as $r) {
                $events[] = ['ts' => Utc::ts($r->at), 'order' => 2, 'kind' => $kind, 'qty' => (int) $r->qty, 'total' => null];
            }
        }

        usort($events, fn ($a, $b) => [$a['ts'], $a['order']] <=> [$b['ts'], $b['order']]);

        [$timeline, $steps, $position] = $this->replay($events);

        return [
            'id' => $itemId,
            'name' => ItemName::display($name, $itemId),
            'timeline' => array_slice(array_reverse($timeline), 0, self::TIMELINE_ROWS),
            'timeline_total' => count($timeline),
            'position' => $position,
            'steps' => $steps,
            'buys' => $this->fills($events, 'buy'),
            'sales' => $this->fills($events, 'sale'),
            'market' => $this->market($stat),
            'daily' => $daily,
        ];
    }

    /**
     * The chart points of one kind of event: when, per-unit price, quantity.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array{ts: int, unit: int, qty: int}>
     */
    private function fills(array $events, string $kind): array
    {
        $points = [];

        foreach ($events as $e) {
            if ($e['kind'] === $kind) {
                $points[] = ['ts' => $e['ts'], 'unit' => intdiv($e['total'], max(1, $e['qty'])), 'qty' => $e['qty']];
            }
        }

        return $points;
    }

    /**
     * @param  list<array<string, mixed>>  $events  sorted
     * @return array{0: list<array<string, mixed>>, 1: list<array{ts: int, held: int, avg: ?float}>, 2: array{held: int, avg: ?float, realized: int, own_qty: int}}
     */
    private function replay(array $events): array
    {
        $first = null;

        foreach ($events as $e) {
            if ($e['kind'] === 'buy') {
                $first = $e['ts'];
                break;
            }
        }

        $held = $cost = $realized = $own = 0;
        $timeline = $steps = [];

        foreach ($events as $e) {
            $row = $e + ['unit' => $e['total'] === null ? null : intdiv($e['total'], max(1, $e['qty'])), 'pnl' => null, 'tracked' => true];

            if ($e['kind'] === 'buy') {
                $held += $e['qty'];
                $cost += $e['total'];
            } elseif (in_array($e['kind'], Trade::CORRECTIONS, true)) {
                if ($first === null || $e['ts'] < $first) {
                    $row['tracked'] = false;
                } else {
                    [$m, $removed] = LedgerService::take($held, $cost, $e['qty']);

                    if ($e['kind'] === Trade::SIDE_WRITEOFF) {
                        $row['pnl'] = -$removed;
                        $realized -= $removed;
                    }

                    $cost -= $removed;
                    $held -= $m;
                }
            } elseif ($e['kind'] === 'sale') {
                if ($first === null || $e['ts'] < $first) {
                    $row['tracked'] = false;
                } else {
                    [$m, $cogs] = LedgerService::take($held, $cost, $e['qty']);
                    $rev = Num::mulDiv($e['total'], $m, $e['qty']);

                    $row['pnl'] = $rev - $cogs;
                    $realized += $rev - $cogs;
                    $own += $e['qty'] - $m;
                    $held -= $m;
                    $cost -= $cogs;
                }
            }

            if (in_array($e['kind'], ['buy', 'sale', ...Trade::CORRECTIONS], true) && $row['tracked']) {
                $steps[] = ['ts' => $e['ts'], 'held' => $held, 'avg' => $held > 0 ? $cost / $held : null];
                $row['held'] = $held;
                $row['avg'] = $held > 0 ? $cost / $held : null;
            }

            $timeline[] = $row;
        }

        return [$timeline, $steps, ['held' => $held, 'avg' => $held > 0 ? $cost / $held : null, 'realized' => $realized, 'own_qty' => $own]];
    }

    /** Market numbers; derived ones are null until the item has enough history. */
    private function market(?object $s): ?array
    {
        if ($s === null) {
            return null;
        }

        return [
            'floor' => (int) $s->floor,
            'quantity' => (int) $s->quantity,
            'recent' => MarketStats::derived($s, 'recent'),
            'typical' => MarketStats::derived($s, 'typical'),
            'hist60' => MarketStats::derived($s, 'hist60'),
            'sold' => MarketStats::derived($s, 'sold_per_day_est'),
            'days' => (int) $s->days_of_data,
            'ready' => MarketStats::ready($s),
            'taken_at' => Utc::ts($s->taken_at),
        ];
    }

    /**
     * Daily floors for the last CHART_DAYS days (anchored at the newest day we hold, not the
     * clock), then the latest snapshot as a final point.
     *
     * @return list<array{ts: int, median: int, min: int}>
     */
    private function daily(string $region, int $itemId, ?object $stat): array
    {
        $newest = DB::table('price_daily')->where('region', $region)->where('item_id', $itemId)->max('day');
        $out = [];

        if ($newest !== null) {
            $from = Utc::day(Utc::ts($newest) - (self::CHART_DAYS - 1) * 86400);

            foreach (DB::table('price_daily')->where('region', $region)->where('item_id', $itemId)->where('day', '>=', $from)
                ->orderBy('day')->get(['day', 'floor_median', 'floor_min']) as $d) {
                // Midday UTC, so the point sits inside its day.
                $out[] = ['ts' => Utc::ts($d->day.' 12:00:00'), 'median' => (int) $d->floor_median, 'min' => (int) $d->floor_min];
            }
        }

        if ($stat !== null) {
            $out[] = ['ts' => Utc::ts($stat->taken_at), 'median' => (int) $stat->floor, 'min' => (int) $stat->floor, 'live' => true];
        }

        return $out;
    }
}
