<?php

namespace App\Console\Commands;

use App\Services\AddonPackager;
use Illuminate\Console\Command;

class AddonPackage extends Command
{
    protected $signature = 'addon:package';

    protected $description = 'Publish the addon zip from addon/ when its version is new (run on app start)';

    public function handle(AddonPackager $packager): int
    {
        [$ok, $message] = $packager->package();

        if (! $ok) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }
}
