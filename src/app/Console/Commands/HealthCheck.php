<?php

namespace App\Console\Commands;

use App\Mail\HealthAlertMail;
use App\Models\FetchRun;
use App\Models\User;
use App\Services\AdminService;
use App\Services\BackupStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the admins when the Blizzard price fetch is failing or stale, or backups have stopped.
 * A problem is mailed once when it starts, again every ledger.health.reminder_hours while it
 * lasts, and once more when it clears. State lives in the cache, so it survives restarts.
 * Nothing is checked for what is not set up (no credentials, no backup service).
 */
class HealthCheck extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Email admins about price fetch failures and stale backups';

    public function handle(AdminService $admin, BackupStatus $backups): int
    {
        $now = time();
        $reminder = (int) config('ledger.health.reminder_hours') * 3600;

        // label => null when healthy (or not applicable), else what is wrong
        $checks = [];

        foreach ($admin->fetchHealth(withCounts: false) as $h) {
            $label = 'Price fetch ('.strtoupper($h['region']).')';

            // Nothing to judge until credentials are set and a first run has happened.
            if (! $h['configured'] || $h['last'] === null) {
                $checks[$label] = null;

                continue;
            }

            $checks[$label] = ! $h['stale'] ? null : ($h['last']->status === FetchRun::STATUS_FAILED
                ? 'the last fetch failed: '.($h['last']->error ?: 'no error recorded').'; last good fetch '.($h['age'] === null ? 'never' : $this->span($h['age']).' ago')
                : ($h['age'] === null ? 'no good fetch yet' : 'no good fetch for '.$this->span($h['age'])));
        }

        $b = $backups->read($now);

        if ($b['configured']) {
            $checks['Database backup'] = ! $b['stale'] ? null : ($b['last'] === null
                ? 'no successful backup has been recorded'
                : "the last good backup was {$b['age_hours']} hours ago");
        }

        $problems = [];
        $recovered = [];
        $record = [];

        foreach ($checks as $label => $what) {
            $key = 'health:notified:'.md5($label);
            $since = Cache::get($key);

            if ($what === null) {
                if ($since !== null) {
                    $recovered[] = $label;
                    $record[$key] = null;
                }

                continue;
            }

            if ($since === null || $now - $since >= $reminder) {
                $problems[$label] = $what;
                $record[$key] = $now;
            }
        }

        if ($problems === [] && $recovered === []) {
            $this->line('Healthy, or nothing new to report.');

            return self::SUCCESS;
        }

        $to = User::where('is_admin', true)->whereNotNull('email_verified_at')->pluck('email')->all();

        if ($to === []) {
            $this->warn('There are problems but no admin to tell: '.implode('; ', array_keys($problems)));

            return self::FAILURE;
        }

        try {
            Mail::to($to)->send(new HealthAlertMail($problems, $recovered));
        } catch (Throwable $e) {
            // State is left alone, so the next run tries again.
            report($e);

            return self::FAILURE;
        }

        foreach ($record as $key => $value) {
            $value === null ? Cache::forget($key) : Cache::forever($key, $value);
        }

        $this->info('Told '.count($to).' admin(s): '.count($problems).' problem(s), '.count($recovered).' recovered.');

        return self::SUCCESS;
    }

    private function span(int $seconds): string
    {
        return intdiv($seconds, 3600) >= 1 ? intdiv($seconds, 3600).' hours' : intdiv($seconds, 60).' minutes';
    }
}
