<?php

namespace App\Console\Commands;

use App\Console\ResolvesUser;
use App\Services\TradesImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class TradesImport extends Command
{
    use ResolvesUser;

    protected $signature = 'trades:import {file : Path to the trades CSV, inside the container} {--user= : Account email or id}';

    protected $description = 'Import a trades CSV (time,item,qty,cost,note) as one user\'s buys (safe to re-run)';

    public function handle(TradesImportService $import): int
    {
        if (($user = $this->userOption()) === null) {
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path} (paths are inside the container).");

            return self::FAILURE;
        }

        try {
            $result = $import->import($user, file_get_contents($path));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['new']} new, {$result['duplicates']} already imported ({$user->email}).");

        return self::SUCCESS;
    }
}
