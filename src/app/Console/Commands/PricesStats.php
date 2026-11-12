<?php

namespace App\Console\Commands;

use App\Console\PricesCommand;
use App\Services\PriceStatsService;

class PricesStats extends PricesCommand
{
    protected $signature = 'prices:stats '.self::REGION_OPTION;

    protected $description = 'Recompute item_stats (recent, typical, 60d, sold/day estimate) from stored snapshots';

    public function handle(PriceStatsService $stats): int
    {
        $count = $stats->recompute($this->region());

        $this->info($count === 0 ? 'No snapshots yet; nothing to compute.' : "Stats recomputed for {$count} items.");

        return self::SUCCESS;
    }
}
