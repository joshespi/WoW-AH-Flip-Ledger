<?php

namespace Tests\Support;

use App\Models\FetchRun;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/** Rows for the shared tables: the item catalog and the price pipeline's output. */
trait SeedsMarketData
{
    /** A catalog item. Unnamed ones are what a price snapshot creates ('none'); named ones come from TSM. */
    protected function seedItem(int $id, ?string $name = null, ?string $source = null, bool $commodity = true, ?string $fetchedAt = null): void
    {
        DB::table('items')->insert([
            'id' => $id, 'name' => $name, 'name_source' => $source ?? ($name === null ? 'none' : 'tsm'),
            'is_commodity' => $commodity, 'fetched_at' => $fetchedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{floor: int, days?: int, recent?: ?int, typical?: ?int, hist60?: ?int, sold?: ?int}>  $stats  by item id
     */
    protected function seedStats(array $stats, string $region = 'us', ?int $takenAt = null): void
    {
        $at = Utc::sql($takenAt ?? time() - 600);

        foreach ($stats as $id => $s) {
            DB::table('item_stats')->updateOrInsert(['region' => $region, 'item_id' => $id], [
                'taken_at' => $at,
                'floor' => $s['floor'],
                'quantity' => 50_000,
                'num_auctions' => 100,
                'recent' => $s['recent'] ?? null,
                'typical' => $s['typical'] ?? null,
                'hist60' => $s['hist60'] ?? null,
                'sold_per_day_est' => $s['sold'] ?? null,
                'days_of_data' => $s['days'] ?? 0,
                'updated_at' => $at,
            ]);
        }
    }

    /** A finished price fetch: `$snapshotAt` is Blizzard's own time, `$finishedAt` ours. */
    protected function seedFetchRun(string $status, int $finishedAt, ?int $snapshotAt = null, string $region = 'us'): void
    {
        FetchRun::create([
            'region' => $region,
            'started_at' => $finishedAt - 30,
            'finished_at' => $finishedAt,
            'status' => $status,
            'last_modified' => $snapshotAt,
        ]);
    }
}
