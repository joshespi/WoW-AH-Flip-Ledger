<?php

namespace Tests\Support;

use App\Models\Upload;
use App\Models\User;
use App\Support\Game;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;

/** Rows a user owns, for tests that need them without running an ingest. */
trait SeedsUserRows
{
    /** A sale as TSM logs it; `$unit` is per unit after the AH cut, `$at` epoch seconds or a UTC string. */
    protected function seedSale(User $user, int $item, int $qty, int $unit, int|string $at, string $game = Game::RETAIL, string $source = 'Auction'): void
    {
        static $n = 0;

        DB::table('tsm_sales')->insert([
            'user_id' => $user->id, 'game' => $game, 'realm' => 'R', 'item_id' => $item, 'qty' => $qty, 'unit_price_copper' => $unit,
            'sold_at' => is_int($at) ? Utc::sql($at) : $at, 'source' => $source, 'dedupe_key' => sha1('seeded sale '.++$n),
        ]);
    }

    /** @param  array<int, int>  $qtyByItem */
    protected function seedInventory(User $user, array $qtyByItem, int|string|null $at = null): void
    {
        $at = is_int($at) ? Utc::sql($at) : ($at ?? Utc::sql());

        foreach ($qtyByItem as $item => $qty) {
            DB::table('inventory')->insert(['user_id' => $user->id, 'game' => Game::RETAIL, 'item_id' => $item, 'qty' => $qty, 'taken_at' => $at]);
        }
    }

    /** @param  array<string, mixed>  $extra  columns to add or override */
    protected function seedUpload(User $user, string $status = Upload::STATUS_OK, array $extra = []): Upload
    {
        return Upload::create($extra + ['user_id' => $user->id, 'source' => Upload::SOURCE_MANUAL, 'received_at' => now(), 'bytes' => 1, 'status' => $status]);
    }
}
