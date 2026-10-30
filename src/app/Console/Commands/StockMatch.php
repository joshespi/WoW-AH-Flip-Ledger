<?php

namespace App\Console\Commands;

use App\Console\ResolvesUser;
use App\Services\PositionsService;
use App\Services\TradesService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class StockMatch extends Command
{
    use ResolvesUser;

    protected $signature = 'stock:match {--user= : Account email or id} {--apply : Write the adjustments (default: list only)}';

    protected $description = 'Remove ledger stock beyond the latest TSM inventory snapshot, at average cost with no P/L';

    public function handle(PositionsService $positions, TradesService $trades): int
    {
        if (($user = $this->userOption()) === null) {
            return self::FAILURE;
        }

        $rows = [];

        foreach ($positions->forUser($user)->positions as $p) {
            if ($p->unaccounted) {
                $rows[] = [$p->itemId, $p->name, $p->held, $p->held - $p->unaccounted, $p->unaccounted];
            }
        }

        if ($rows === []) {
            $this->info('Nothing to match: no position holds more than the latest TSM snapshot.');

            return self::SUCCESS;
        }

        $this->table(['Item', 'Name', 'Ledger', 'TSM stock', 'Remove'], $rows);

        if (! $this->option('apply')) {
            $this->line('Dry run. Add --apply to write these adjustments.');

            return self::SUCCESS;
        }

        foreach ($rows as [$id, , , , $qty]) {
            try {
                $trades->adjust($user, $id, $qty, time(), 'Matched to TSM stock');
            } catch (InvalidArgumentException $e) {
                $this->error("Item {$id}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info(count($rows).' adjustments written.');

        return self::SUCCESS;
    }
}
