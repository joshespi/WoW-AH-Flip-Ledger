<?php

namespace App\Console\Commands;

use App\Console\PricesCommand;
use App\Services\PriceRollup;
use App\Services\PriceStatsService;

class PricesRollup extends PricesCommand
{
    protected $signature = 'prices:rollup {--region= : Region code (default: BLIZZARD_REGION)} {--days=2 : How many UTC days back to roll up, including today} {--stats : Also recompute item stats}';

    protected $description = 'Roll hourly price snapshots up into daily rows (safe to re-run)';

    public function handle(PriceRollup $rollup, PriceStatsService $stats): int
    {
        $region = $this->region();
        $days = max(1, (int) $this->option('days'));

        $this->info("Rolled up {$rollup->rollupRecent($region, $days)} item-days for the last {$days} day(s).");

        if ($this->option('stats')) {
            $this->info("Stats recomputed for {$stats->recompute($region)} items.");
        }

        return self::SUCCESS;
    }
}
