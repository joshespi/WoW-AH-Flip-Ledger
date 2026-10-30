<?php

namespace App\Services;

use App\Support\Bulk;
use App\Support\Rows;
use App\Support\Stats;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes item_stats from our own snapshots. Blizzard publishes only current listings, so
 * everything beyond the floor is derived, and honest about it:
 *
 *   recent            median floor over the last 24 h
 *   typical           median of daily median floors over 30 d
 *   hist60            mean of daily median floors over 60 d
 *   sold_per_day_est  sum of hourly supply drops over sold_window_days, per day (an estimate:
 *                     cancels and expiries also reduce supply, and a relist within the same hour
 *                     hides a sale)
 *   days_of_data      distinct days with data in the last 60 d, so the UI can say "collecting data"
 *
 * Windows are anchored at the latest snapshot rather than the wall clock, so if fetching stops the
 * numbers stay self-consistent and taken_at shows how old they are.
 */
class PriceStatsService
{
    /**
     * @return int items written
     */
    public function recompute(string $region): int
    {
        $latest = DB::table('price_snapshots')->where('region', $region)->max('taken_at');

        if ($latest === null) {
            return 0;
        }

        $anchor = Utc::ts($latest);
        $current = DB::table('price_snapshots')->where('region', $region)->where('taken_at', $latest)
            ->get(['item_id', 'floor', 'quantity', 'num_auctions'])->keyBy('item_id');

        $recent = $this->recent($region, $anchor);
        [$typical, $hist60, $days] = $this->daily($region, $anchor);
        $sold = $this->sold($region, $anchor);

        $now = Utc::sql();
        $rows = [];

        foreach ($current as $id => $c) {
            $id = (int) $id;
            $rows[] = [
                'region' => $region,
                'item_id' => $id,
                'taken_at' => $latest,
                'floor' => $c->floor,
                'quantity' => $c->quantity,
                'num_auctions' => $c->num_auctions,
                'recent' => $recent[$id] ?? null,
                'typical' => $typical[$id] ?? null,
                'hist60' => $hist60[$id] ?? null,
                'sold_per_day_est' => $sold[$id] ?? null,
                'days_of_data' => $days[$id] ?? 0,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows, $region, $latest) {
            Bulk::upsert('item_stats', $rows, ['region', 'item_id'], [
                'taken_at', 'floor', 'quantity', 'num_auctions', 'recent', 'typical', 'hist60',
                'sold_per_day_est', 'days_of_data', 'updated_at',
            ]);

            // Items with no listings in the latest snapshot have no floor; drop their stale rows.
            DB::table('item_stats')->where('region', $region)->where('taken_at', '<', $latest)->delete();
        });

        return count($rows);
    }

    /**
     * @return array<int, int> item id => median floor over the last 24 h
     */
    private function recent(string $region, int $anchor): array
    {
        $rows = DB::table('price_snapshots')->where('region', $region)
            ->where('taken_at', '>', Utc::sql($anchor - 86400))
            ->where('taken_at', '<=', Utc::sql($anchor))
            ->orderBy('item_id')->select('item_id', 'floor')->cursor();

        $out = [];

        foreach (Rows::byItem($rows) as $id => $group) {
            $out[$id] = Stats::median(Rows::ints($group, 'floor'));
        }

        return $out;
    }

    /**
     * @return array{array<int, int>, array<int, int>, array<int, int>} typical, hist60, days_of_data by item id
     */
    private function daily(string $region, int $anchor): array
    {
        $first60 = Utc::day($anchor - 59 * 86400);
        $first30 = Utc::day($anchor - 29 * 86400);
        $last = Utc::day($anchor);

        $typical = $hist60 = $days = [];

        $rows = DB::table('price_daily')->where('region', $region)->whereBetween('day', [$first60, $last])
            ->orderBy('item_id')->orderBy('day')->select('item_id', 'day', 'floor_median')->cursor();

        foreach (Rows::byItem($rows) as $id => $group) {
            $all = Rows::ints($group, 'floor_median');
            $thirty = Rows::ints(array_filter($group, fn (object $r): bool => $r->day >= $first30), 'floor_median');

            $hist60[$id] = Stats::mean($all);
            $typical[$id] = Stats::median($thirty ?: $all);
            $days[$id] = count($all);
        }

        return [$typical, $hist60, $days];
    }

    /**
     * @return array<int, int> item id => estimated units sold per day
     */
    private function sold(string $region, int $anchor): array
    {
        $window = (int) config('ledger.prices.sold_window_days');
        $from = $anchor - $window * 86400;

        $first = DB::table('price_snapshots')->where('region', $region)
            ->where('taken_at', '>', Utc::sql($from))->min('taken_at');

        if ($first === null) {
            return [];
        }

        // Days actually covered, at least one, so a single hour of data isn't scaled up 24x.
        $span = max(1.0, ($anchor - Utc::ts($first)) / 86400);

        // Each snapshot's drop from the one before it for the same item (the first has none), summed
        // per item in the database instead of streaming every row of the window through PHP.
        $withPrevious = DB::table('price_snapshots')->where('region', $region)->where('taken_at', '>', Utc::sql($from))
            ->selectRaw('item_id, quantity, lag(quantity) over (partition by item_id order by taken_at) as prev_quantity');

        $drop = 'sum(case when prev_quantity > quantity then prev_quantity - quantity else 0 end)';

        $totals = DB::query()->fromSub($withPrevious, 'w')
            ->selectRaw("item_id, {$drop} as drops")->groupBy('item_id')->havingRaw("{$drop} > 0")
            ->pluck('drops', 'item_id');

        return $totals->mapWithKeys(fn ($total, $id) => [(int) $id => (int) round($total / $span)])->all();
    }
}
