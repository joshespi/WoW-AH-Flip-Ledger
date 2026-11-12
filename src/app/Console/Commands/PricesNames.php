<?php

namespace App\Console\Commands;

use App\Console\PricesCommand;
use App\Services\Blizzard\BlizzardClient;
use App\Services\ItemNameFetcher;

class PricesNames extends PricesCommand
{
    protected $signature = 'prices:names '.self::REGION_OPTION.' {--limit= : Items to look up this run (default: ledger.prices.names_per_run)}';

    protected $description = 'Look up names for commodities from the Blizzard item API (paced; each item is looked up once)';

    public function handle(BlizzardClient $client, ItemNameFetcher $names): int
    {
        if (! $this->credentialsSet($client)) {
            return self::FAILURE;
        }

        $r = $names->fetch($this->region(), (int) ($this->option('limit') ?: config('ledger.prices.names_per_run')));

        $this->info("Named {$r['updated']} items; Blizzard has no record of {$r['not_found']}.");

        if ($r['stopped'] !== null) {
            $this->warn("Stopped early: {$r['stopped']} The rest will be retried next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
