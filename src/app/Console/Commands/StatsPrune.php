<?php

namespace App\Console\Commands;

use App\Support\Utc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StatsPrune extends Command
{
    protected $signature = 'stats:prune';

    protected $description = 'Delete user activity days older than ledger.stats.activity_days';

    public function handle(): int
    {
        $keep = (int) config('ledger.stats.activity_days');
        $n = DB::table('user_activity_days')->where('day', '<', Utc::day(time() - $keep * 86400))->delete();

        $this->info("Pruned {$n} activity days.");

        return self::SUCCESS;
    }
}
