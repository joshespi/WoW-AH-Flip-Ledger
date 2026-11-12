<?php

namespace App\Console\Commands;

use App\Console\PricesCommand;
use App\Models\FetchRun;
use App\Services\Blizzard\BlizzardClient;
use App\Services\PriceFetchService;
use App\Services\PriceRollup;
use App\Services\PriceStatsService;
use Illuminate\Support\Facades\Cache;

class PricesFetch extends PricesCommand
{
    protected $signature = 'prices:fetch '.self::REGION_OPTION.' {--force : Ignore Last-Modified and reprocess the current snapshot}';

    /** Longer than any real run; a run that dies frees the lock after this. */
    private const LOCK_SECONDS = 30 * 60;

    protected $description = 'Fetch Blizzard commodity prices, store a snapshot, then roll up the day and recompute item stats';

    public function handle(BlizzardClient $client, PriceFetchService $fetch, PriceRollup $rollup, PriceStatsService $stats): int
    {
        if (! $this->credentialsSet($client)) {
            return self::FAILURE;
        }

        $region = $this->region();

        // One fetch per region at a time, however it was started (schedule, shell, admin "Fetch now").
        $lock = Cache::lock("prices:fetch:{$region}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('A price fetch for this region is already running.');

            return self::FAILURE;
        }

        try {
            return $this->fetchAndDerive($region, $fetch, $rollup, $stats);
        } finally {
            $lock->release();
        }
    }

    private function fetchAndDerive(string $region, PriceFetchService $fetch, PriceRollup $rollup, PriceStatsService $stats): int
    {
        $run = $fetch->fetch($region, (bool) $this->option('force'));

        if ($run->status === FetchRun::STATUS_FAILED) {
            $this->error("Fetch failed: {$run->error}");

            return self::FAILURE;
        }

        if ($run->status === FetchRun::STATUS_UNCHANGED) {
            $this->info('Blizzard has not published a new snapshot since the last fetch.');

            return self::SUCCESS;
        }

        $rollup->rollupRecent($region);
        $count = $stats->recompute($region);

        $this->info(sprintf(
            'Stored %s items from %s auctions (Blizzard snapshot %s UTC); stats updated for %s items.',
            number_format($run->items), number_format($run->auctions), $run->last_modified?->format('Y-m-d H:i') ?? 'time unknown', number_format($count),
        ));

        return self::SUCCESS;
    }
}
