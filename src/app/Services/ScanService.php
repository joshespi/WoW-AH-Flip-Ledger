<?php

namespace App\Services;

use App\Models\User;
use App\Support\ItemName;
use App\Support\MarketStats;
use Illuminate\Support\Facades\DB;

/**
 * Finds items trading cheap against their own history. Only items with enough history
 * (ledger.prices.min_days) are considered, because the filters use derived numbers.
 * Cheapest versus typical first.
 */
class ScanService
{
    /**
     * @param  ?float  $maxRatio  floor / typical at or under this (0.75 = 25% under)
     * @return list<array<string, mixed>>
     */
    public function scan(User $user, int $minItemId = 0, int $minSoldPerDay = 0, ?float $maxRatio = null, ?int $top = null): array
    {
        $top = max(1, min($top ?? (int) config('ledger.market.scan_default_top'), (int) config('ledger.market.scan_max_top')));

        return DB::table('item_stats as s')
            ->leftJoin('items', 'items.id', '=', 's.item_id')
            ->leftJoin('watchlist_items as w', fn ($j) => $j->on('w.item_id', '=', 's.item_id')->ownedBy($user, 'w.user_id'))
            ->where('s.region', $user->region)
            ->where('s.days_of_data', '>=', MarketStats::minDays())
            ->where('s.typical', '>', 0)->where('s.floor', '>', 0)
            ->where('s.item_id', '>=', max(0, $minItemId))
            ->when($minSoldPerDay > 0, fn ($q) => $q->where('s.sold_per_day_est', '>=', $minSoldPerDay))
            ->when($maxRatio !== null, fn ($q) => $q->whereRaw('s.floor * 1.0 <= s.typical * ?', [$maxRatio]))
            ->orderByRaw('s.floor * 1.0 / s.typical')->orderBy('s.item_id')
            ->limit($top)
            ->get(['s.item_id', 'items.name', 's.floor', 's.recent', 's.typical', 's.sold_per_day_est', 's.days_of_data', 'w.id as watched'])
            ->map(fn ($r) => [
                'item_id' => $id = (int) $r->item_id,
                'name' => ItemName::display($r->name, $id),
                'floor' => (int) $r->floor,
                'recent' => $recent = MarketStats::derived($r, 'recent'),
                'typical' => (int) $r->typical,
                'sold' => MarketStats::derived($r, 'sold_per_day_est'),
                'ratio' => $r->floor / $r->typical,
                'knife' => FallingKnife::is((int) $r->floor, $recent, (int) $r->typical),
                'watched' => $r->watched !== null,
            ])->all();
    }
}
