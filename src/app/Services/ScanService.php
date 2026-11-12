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

/**
 * Finds items trading cheap against their own history. Only items with enough history
 * (ledger.prices.min_days) are considered, because the filters use derived numbers.
 * Modes (discount, margin, volume, winners; anything else is discount) pick the ranking: cheapest versus typical, biggest margin after the AH cut, fastest
 * sellers, or items the user has already profited on that are cheap again.
 */
class ScanService
{
    public const SPARK_DAYS = 14;

    /**
     * @param  ?float  $maxRatio  floor / typical at or under this (0.75 = 25% under)
     * @return list<array<string, mixed>>
     */
    public function scan(User $user, int $minItemId = 0, int $minSoldPerDay = 0, ?float $maxRatio = null, ?int $top = null, string $mode = 'discount'): array
    {
        $top = max(1, min($top ?? (int) config('ledger.market.scan_default_top'), (int) config('ledger.market.scan_max_top')));
        $net = Money::NET;

        $query = DB::table('item_stats as s')
            ->leftJoin('items', 'items.id', '=', 's.item_id')
            ->leftJoin('watchlist_items as w', fn ($j) => $j->on('w.item_id', '=', 's.item_id')->ownedBy($user, 'w.user_id'))
            ->where('s.region', $user->region)
            ->where('s.days_of_data', '>=', MarketStats::minDays())
            ->where('s.typical', '>', 0)->where('s.floor', '>', 0)
            ->where('s.item_id', '>=', max(0, $minItemId))
            ->when($minSoldPerDay > 0, fn ($q) => $q->where('s.sold_per_day_est', '>=', $minSoldPerDay))
            ->when($maxRatio !== null, fn ($q) => $q->whereRaw('s.floor * 1.0 <= s.typical * ?', [$maxRatio]));

        match ($mode) {
            'margin' => $query->whereRaw('s.typical * ? > s.floor', [$net])
                ->orderByRaw('(s.typical * ? - s.floor) desc', [$net]),
            'volume' => $query->whereNotNull('s.sold_per_day_est')->whereRaw('s.floor <= s.typical')
                ->orderByDesc('s.sold_per_day_est'),
            'winners' => $query->whereIn('s.item_id', $this->winnerIds($user) ?: [0])->whereRaw('s.floor <= s.typical')
                ->orderByRaw('s.floor * 1.0 / s.typical'),
            default => $query->orderByRaw('s.floor * 1.0 / s.typical'),
        };

        $rows = $query->orderBy('s.item_id')->limit($top)
            ->get(['s.item_id', 'items.name', 's.floor', 's.recent', 's.typical', 's.sold_per_day_est', 's.days_of_data', 'w.id as watched'])
            ->map(fn ($r) => [
                'item_id' => $id = (int) $r->item_id,
                'name' => ItemName::display($r->name, $id),
                'floor' => (int) $r->floor,
                'recent' => $recent = MarketStats::derived($r, 'recent'),
                'typical' => (int) $r->typical,
                'sold' => MarketStats::derived($r, 'sold_per_day_est'),
                'ratio' => $r->floor / $r->typical,
                // Per unit: sell at typical, pay the cut, buy at the floor.
                'margin' => $margin = Money::round($r->typical * $net) - (int) $r->floor,
                'roi' => $margin / $r->floor,
                'knife' => FallingKnife::is((int) $r->floor, $recent, (int) $r->typical),
                'watched' => $r->watched !== null,
            ])->all();

        $spark = $this->sparklines(array_column($rows, 'item_id'), $user->region);

        return array_map(fn (array $r) => $r + ['spark' => $spark[$r['item_id']] ?? []], $rows);
    }

    /** Most days of price history any item has: how close the scan is to having enough data. */
    public function daysCollected(User $user): int
    {
        return (int) DB::table('item_stats')->where('region', $user->region)->max('days_of_data');
    }

    /**
     * Items where the user's average sale (stored after the cut) beat their average buy.
     *
     * @return list<int>
     */
    private function winnerIds(User $user): array
    {
        $buys = DB::table('trades')->ownedBy($user)->inGame(Game::RETAIL)->where('side', Trade::SIDE_BUY)->where('qty', '>', 0)
            ->groupBy('item_id')->selectRaw('item_id, sum(cost_copper) * 1.0 / sum(qty) as unit')->pluck('unit', 'item_id');

        $sales = DB::table('tsm_sales')->ownedBy($user)->inGame(Game::RETAIL)->where('source', TsmData::SOURCE_AUCTION)->where('qty', '>', 0)
            ->groupBy('item_id')->selectRaw('item_id, sum(qty * unit_price_copper) * 1.0 / sum(qty) as unit')->pluck('unit', 'item_id');

        $ids = [];

        foreach ($sales as $id => $unit) {
            if (isset($buys[$id]) && $unit > $buys[$id]) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The last SPARK_DAYS daily median floors per item, oldest first, anchored at the newest day held.
     *
     * @param  list<int>  $ids
     * @return array<int, list<int>>
     */
    private function sparklines(array $ids, string $region): array
    {
        $newest = $ids === [] ? null : DB::table('price_daily')->where('region', $region)->max('day');

        if ($newest === null) {
            return [];
        }

        $out = [];

        foreach (DB::table('price_daily')->where('region', $region)->whereIn('item_id', $ids)
            ->where('day', '>=', Utc::day(Utc::daysAgo(self::SPARK_DAYS - 1, Utc::ts($newest))))
            ->orderBy('item_id')->orderBy('day')->get(['item_id', 'floor_median']) as $d) {
            $out[(int) $d->item_id][] = (int) $d->floor_median;
        }

        return $out;
    }
}
