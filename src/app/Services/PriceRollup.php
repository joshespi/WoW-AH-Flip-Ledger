<?php

namespace App\Services;

use App\Support\Bulk;
use App\Support\Rows;
use App\Support\Stats;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/**
 * Collapses a UTC day of hourly snapshots into one price_daily row per item. Idempotent: rolling
 * a day up again (it is redone while the day is still filling) replaces the row.
 */
class PriceRollup
{
    /**
     * Roll up today and the previous days-1 days.
     */
    public function rollupRecent(string $region, int $days = 2): int
    {
        $rows = 0;

        for ($i = 0; $i < $days; $i++) {
            $rows += $this->rollupDay($region, Utc::day(time() - $i * 86400));
        }

        return $rows;
    }

    /**
     * @param  string  $day  UTC date, Y-m-d
     * @return int rows written
     */
    public function rollupDay(string $region, string $day): int
    {
        $snapshots = DB::table('price_snapshots')
            ->where('region', $region)
            ->whereBetween('taken_at', [$day.' 00:00:00', $day.' 23:59:59'])
            ->orderBy('item_id')
            ->select('item_id', 'floor', 'quantity', 'num_auctions')
            ->cursor();

        $out = [];

        foreach (Rows::byItem($snapshots) as $id => $group) {
            $floors = Rows::ints($group, 'floor');

            $out[] = [
                'region' => $region,
                'day' => $day,
                'item_id' => $id,
                'floor_min' => min($floors),
                'floor_median' => Stats::median($floors),
                'qty_avg' => Stats::mean(Rows::ints($group, 'quantity')),
                'auctions_avg' => Stats::mean(Rows::ints($group, 'num_auctions')),
                'samples' => count($floors),
            ];
        }

        return Bulk::upsert('price_daily', $out, ['region', 'day', 'item_id'], ['floor_min', 'floor_median', 'qty_avg', 'auctions_avg', 'samples']);
    }
}
