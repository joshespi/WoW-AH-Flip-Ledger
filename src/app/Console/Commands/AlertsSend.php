<?php

namespace App\Console\Commands;

use App\Models\FetchRun;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AlertsSend extends Command
{
    protected $signature = 'alerts:send {--force : Run even if this price refresh was already handled or is old}';

    protected $description = 'Email price alerts to opted-in users after a new price refresh';

    public function handle(AlertService $alerts): int
    {
        $failed = false;

        foreach (array_keys(config('ledger.regions')) as $region) {
            // Only a refresh that brought new prices counts; 'unchanged' and 'failed' runs do not.
            $run = FetchRun::where('region', $region)->where('status', FetchRun::STATUS_OK)->latest('started_at')->latest('id')->first();
            $marker = "alerts:last-run:{$region}";

            if ($run === null) {
                $this->line("{$region}: no price refresh yet.");

                continue;
            }

            if (! $this->option('force')) {
                if (Cache::get($marker) === $run->id) {
                    $this->line("{$region}: no new prices since the last alert run.");

                    continue;
                }

                if (($run->finished_at ?? $run->started_at)->lt(now()->subHours((int) config('ledger.prices.stale_hours')))) {
                    $this->warn("{$region}: the newest prices are stale, not alerting on them.");

                    continue;
                }
            }

            $sent = $users = 0;
            $regionFailed = false;

            User::where('region', $region)->where('alerts_enabled', true)->where('is_demo', false)->whereNotNull('email_verified_at')
                ->chunkById(100, function ($chunk) use ($alerts, &$sent, &$users, &$regionFailed) {
                    foreach ($chunk as $user) {
                        $users++;

                        try {
                            $sent += $alerts->run($user);
                        } catch (Throwable $e) {
                            // Its states were not saved, so the next run tries again.
                            report($e);
                            $regionFailed = true;
                        }
                    }
                });

            // A failure keeps the marker, so the whole region is looked at again; users already
            // handled have their states saved and will not be mailed twice.
            if (! $regionFailed) {
                Cache::forever($marker, $run->id);
            }

            $failed = $failed || $regionFailed;
            $this->info("{$region}: {$sent} alerts for {$users} users".($regionFailed ? ' (some failed, will retry)' : '').'.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
