<?php

namespace App\Services;

use App\Support\Bounds;

/**
 * Reads the marker the backup service writes after each good dump (epoch seconds in
 * ledger.backup.status_file). Only the timestamp is visible to the app; the dumps are not
 * mounted for reading.
 */
class BackupStatus
{
    /**
     * @return array{configured: bool, last: ?int, age_hours: ?int, stale: bool}
     */
    public function read(?int $now = null): array
    {
        $path = config('ledger.backup.status_file');

        if (! $path) {
            return ['configured' => false, 'last' => null, 'age_hours' => null, 'stale' => false];
        }

        $raw = @file_get_contents($path);
        $last = $raw === false ? null : Bounds::count(trim($raw));
        $age = $last === null ? null : intdiv(($now ?? time()) - $last, 3600);

        return [
            'configured' => true,
            'last' => $last,
            'age_hours' => $age,
            // No marker at all counts as stale: the service never completed a dump.
            'stale' => $last === null || $age >= (int) config('ledger.backup.max_age_hours'),
        ];
    }
}
