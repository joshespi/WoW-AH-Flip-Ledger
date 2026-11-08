<?php

namespace App\Console\Commands;

use App\Services\ScoreboardService;
use Illuminate\Console\Command;

class ScoreboardBuild extends Command
{
    protected $signature = 'scoreboard:build';

    protected $description = 'Recompute the public scoreboard entries for every opted-in user';

    public function handle(ScoreboardService $scoreboard): int
    {
        $this->info(sprintf('Rebuilt %d scoreboard users.', $scoreboard->rebuild()));

        return self::SUCCESS;
    }
}
