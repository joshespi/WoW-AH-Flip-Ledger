<?php

namespace App\Console\Commands;

use App\Services\PricePruner;
use Illuminate\Console\Command;

class PricesPrune extends Command
{
    protected $signature = 'prices:prune';

    protected $description = 'Delete old hourly snapshots (after rolling them up), old daily rows and old fetch runs';

    public function handle(PricePruner $pruner): int
    {
        $r = $pruner->prune();

        $this->info(sprintf('Pruned %s snapshots, %s daily rows, %s fetch runs.', number_format($r['snapshots']), number_format($r['daily']), number_format($r['runs'])));

        return self::SUCCESS;
    }
}
