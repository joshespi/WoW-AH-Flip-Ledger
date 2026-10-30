<?php

namespace App\Console;

use App\Services\Blizzard\BlizzardClient;
use Illuminate\Console\Command;

/** Shared bits of the prices:* commands. */
abstract class PricesCommand extends Command
{
    /** The --region option, else BLIZZARD_REGION. */
    protected function region(): string
    {
        return (string) ($this->option('region') ?: config('services.blizzard.region'));
    }

    /** False (after saying how to fix it) when the Blizzard credentials are missing. */
    protected function credentialsSet(BlizzardClient $client): bool
    {
        if ($client->configured()) {
            return true;
        }

        $this->error('Blizzard credentials are not set (BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET in .env).');

        return false;
    }
}
