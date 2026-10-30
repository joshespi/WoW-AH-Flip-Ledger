<?php

namespace App\Services;

use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/**
 * Retention: hourly snapshots live for snapshot_days, after which only the daily rollup remains.
 * Any day about to lose its snapshots is rolled up first, so an outage of the hourly rollup can't
 * lose history.
 */
class PricePruner
{
    public function __construct(private readonly PriceRollup $rollup) {}

    /**
     * @return array{snapshots: int, daily: int, runs: int}
     */
    public function prune(): array
    {
        $cutoff = Utc::sql(time() - (int) config('ledger.prices.snapshot_days') * 86400);

        $pending = DB::table('price_snapshots')->where('taken_at', '<', $cutoff)
            ->selectRaw('region, date(taken_at) as day')->distinct()->orderBy('day')->get();

        foreach ($pending as $p) {
            $this->rollup->rollupDay($p->region, (string) $p->day);
        }

        return [
            'snapshots' => DB::table('price_snapshots')->where('taken_at', '<', $cutoff)->delete(),
            'daily' => DB::table('price_daily')
                ->where('day', '<', Utc::day(time() - (int) config('ledger.prices.daily_days') * 86400))->delete(),
            'runs' => DB::table('fetch_runs')
                ->where('started_at', '<', Utc::sql(time() - (int) config('ledger.prices.fetch_run_days') * 86400))->delete(),
        ];
    }
}
