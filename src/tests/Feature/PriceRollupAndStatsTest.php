<?php

namespace Tests\Feature;

use App\Services\PricePruner;
use App\Services\PriceRollup;
use App\Services\PriceStatsService;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stats windows are anchored at the latest snapshot, not the clock, so these tests use fixed
 * dates and are deterministic.
 */
class PriceRollupAndStatsTest extends TestCase
{
    use RefreshDatabase;

    private const ANCHOR = '2026-10-20 12:00:00';

    private function snap(string $at, int $item, int $floor, int $qty = 100, int $auctions = 3, string $region = 'us'): void
    {
        DB::table('price_snapshots')->insert(['region' => $region, 'taken_at' => $at, 'item_id' => $item, 'floor' => $floor, 'quantity' => $qty, 'num_auctions' => $auctions]);
    }

    private function at(int $hoursBeforeAnchor): string
    {
        return Utc::sql(Utc::ts(self::ANCHOR) - $hoursBeforeAnchor * 3600);
    }

    private function daily(string $day, int $item, int $median, string $region = 'us'): void
    {
        DB::table('price_daily')->insert(['region' => $region, 'day' => $day, 'item_id' => $item, 'floor_min' => $median, 'floor_median' => $median, 'qty_avg' => 1, 'auctions_avg' => 1, 'samples' => 1]);
    }

    private function dayBefore(int $days): string
    {
        return gmdate('Y-m-d', Utc::ts(self::ANCHOR) - $days * 86400);
    }

    private function stat(int $item, string $region = 'us'): ?object
    {
        return DB::table('item_stats')->where('region', $region)->where('item_id', $item)->first();
    }

    // ---- rollup ----

    public function test_a_day_rolls_up_to_min_median_averages_and_sample_count(): void
    {
        foreach ([[10, 10, 500, 2], [11, 30, 300, 4], [12, 20, 400, 3], [13, 40, 200, 5]] as [$h, $floor, $qty, $n]) {
            $this->snap("2026-10-20 {$h}:00:00", 7, $floor, $qty, $n);
        }

        $this->assertSame(1, app(PriceRollup::class)->rollupDay('us', '2026-10-20'));

        $this->assertEquals(
            ['floor_min' => 10, 'floor_median' => 25, 'qty_avg' => 350, 'auctions_avg' => 4, 'samples' => 4],
            (array) DB::table('price_daily')->where('item_id', 7)->first(['floor_min', 'floor_median', 'qty_avg', 'auctions_avg', 'samples']),
        );
    }

    public function test_rolling_up_again_replaces_the_row_and_only_touches_that_day_and_region(): void
    {
        $this->snap('2026-10-20 10:00:00', 7, 100);
        $this->snap('2026-10-21 10:00:00', 7, 900);
        $this->snap('2026-10-20 10:00:00', 7, 555, region: 'eu');
        $rollup = app(PriceRollup::class);

        $rollup->rollupDay('us', '2026-10-20');
        $this->snap('2026-10-20 11:00:00', 7, 300); // the day kept filling
        $rollup->rollupDay('us', '2026-10-20');

        $this->assertSame(1, DB::table('price_daily')->count());
        $this->assertEquals(['floor_min' => 100, 'floor_median' => 200, 'samples' => 2], (array) DB::table('price_daily')->first(['floor_min', 'floor_median', 'samples']));
    }

    public function test_rollup_handles_more_items_than_one_write_chunk(): void
    {
        $rows = [];
        for ($id = 1; $id <= 1_300; $id++) {
            foreach ([10, 11] as $h) {
                $rows[] = ['region' => 'us', 'taken_at' => "2026-10-20 {$h}:00:00", 'item_id' => $id, 'floor' => $id * 10 + $h, 'quantity' => 5, 'num_auctions' => 1];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('price_snapshots')->insert($chunk);
        }

        $this->assertSame(1_300, app(PriceRollup::class)->rollupDay('us', '2026-10-20'));
        $this->assertSame(1_300, DB::table('price_daily')->count());
        // Floors are id*10+10 and id*10+11; the median of 13010 and 13011 rounds half up.
        $this->assertSame(13_011, (int) DB::table('price_daily')->where('item_id', 1_300)->value('floor_median'));
        $this->assertSame(7_010, (int) DB::table('price_daily')->where('item_id', 700)->value('floor_min'));
    }

    public function test_rollup_recent_covers_today_and_yesterday(): void
    {
        $this->snap(gmdate('Y-m-d').' 00:00:01', 7, 100);
        $this->snap(gmdate('Y-m-d', time() - 86400).' 12:00:00', 7, 200);
        $this->snap(gmdate('Y-m-d', time() - 3 * 86400).' 12:00:00', 7, 300);

        $this->assertSame(2, app(PriceRollup::class)->rollupRecent('us', 2));
    }

    // ---- stats ----

    public function test_recent_is_the_median_floor_of_the_last_24_hours(): void
    {
        foreach ([[5, 10], [4, 12], [3, 11], [2, 13], [1, 14], [0, 15]] as [$ago, $floor]) {
            $this->snap($this->at($ago), 1, $floor);
        }
        $this->snap($this->at(30), 1, 99_999); // outside the window

        app(PriceStatsService::class)->recompute('us');

        $this->assertSame(13, (int) $this->stat(1)->recent);
        $this->assertSame(15, (int) $this->stat(1)->floor);
    }

    public function test_typical_hist60_and_days_of_data_come_from_daily_rows(): void
    {
        $this->snap($this->at(0), 1, 100);
        // 15 old days at 400 (inside 60 d, outside 30 d) and 30 recent days at 100.
        for ($d = 44; $d >= 30; $d--) {
            $this->daily($this->dayBefore($d), 1, 400);
        }
        for ($d = 29; $d >= 0; $d--) {
            $this->daily($this->dayBefore($d), 1, 100);
        }
        $this->daily($this->dayBefore(61), 1, 9_999); // older than 60 d: ignored

        app(PriceStatsService::class)->recompute('us');

        $s = $this->stat(1);
        $this->assertSame(100, (int) $s->typical, 'median over the last 30 days only');
        $this->assertSame(200, (int) $s->hist60, '(15*400 + 30*100) / 45');
        $this->assertSame(45, (int) $s->days_of_data);
    }

    public function test_an_item_with_no_daily_history_has_no_derived_numbers_yet(): void
    {
        $this->snap($this->at(0), 1, 100);

        app(PriceStatsService::class)->recompute('us');

        $s = $this->stat(1);
        $this->assertNull($s->typical);
        $this->assertNull($s->hist60);
        $this->assertSame(0, (int) $s->days_of_data);
        $this->assertSame(100, (int) $s->recent);
    }

    public function test_sold_per_day_is_the_sum_of_hourly_supply_drops_per_day_covered(): void
    {
        // Hourly quantities 1000, 900, 950, 800, 800, 700: drops of 100 + 150 + 100 within one day.
        foreach ([[5, 1000], [4, 900], [3, 950], [2, 800], [1, 800], [0, 700]] as [$ago, $qty]) {
            $this->snap($this->at($ago), 1, 10, $qty);
        }

        app(PriceStatsService::class)->recompute('us');

        $this->assertSame(350, (int) $this->stat(1)->sold_per_day_est);
    }

    public function test_sold_per_day_is_spread_over_the_days_actually_covered(): void
    {
        $this->snap($this->at(72), 1, 10, 1000); // three days before the anchor
        $this->snap($this->at(0), 1, 10, 400);

        app(PriceStatsService::class)->recompute('us');

        $this->assertSame(200, (int) $this->stat(1)->sold_per_day_est, '600 units over 3 days');
    }

    public function test_supply_increases_and_other_items_do_not_leak_into_the_estimate(): void
    {
        $this->snap($this->at(2), 1, 10, 100);
        $this->snap($this->at(1), 1, 10, 500); // a big relist
        $this->snap($this->at(0), 1, 10, 500);
        $this->snap($this->at(2), 2, 10, 900);
        $this->snap($this->at(0), 2, 10, 800);

        app(PriceStatsService::class)->recompute('us');

        $this->assertSame(0, (int) ($this->stat(1)->sold_per_day_est ?? 0));
        $this->assertSame(100, (int) $this->stat(2)->sold_per_day_est);
    }

    public function test_sold_window_ignores_older_history(): void
    {
        config(['ledger.prices.sold_window_days' => 7]);
        $this->snap($this->at(24 * 20), 1, 10, 9_000); // 20 days ago: outside the window
        $this->snap($this->at(24 * 19), 1, 10, 1_000);
        $this->snap($this->at(0), 1, 10, 1_000);

        app(PriceStatsService::class)->recompute('us');

        $this->assertSame(0, (int) ($this->stat(1)->sold_per_day_est ?? 0));
    }

    public function test_items_that_left_the_auction_house_lose_their_stats_row(): void
    {
        $this->snap($this->at(1), 1, 10);
        $this->snap($this->at(1), 2, 20);
        $this->snap($this->at(0), 1, 11);
        $service = app(PriceStatsService::class);

        $service->recompute('us');

        $this->assertNotNull($this->stat(1));
        $this->assertNull($this->stat(2), 'item 2 has no listing in the latest snapshot');
        $this->assertSame(11, (int) $this->stat(1)->floor);
    }

    public function test_recompute_is_idempotent_and_regions_are_separate(): void
    {
        $this->snap($this->at(0), 1, 11);
        $this->snap($this->at(0), 1, 77, region: 'eu');
        $service = app(PriceStatsService::class);

        $this->assertSame(1, $service->recompute('us'));
        $first = (array) $this->stat(1);
        $this->assertSame(1, $service->recompute('us'));

        $this->assertSame(1, DB::table('item_stats')->count());
        $this->assertEquals($first['floor'], $this->stat(1)->floor);
        $this->assertNull($this->stat(1, 'eu'), 'recomputing us must not touch eu');

        $service->recompute('eu');
        $this->assertSame(77, (int) $this->stat(1, 'eu')->floor);
        $this->assertSame(11, (int) $this->stat(1)->floor);
    }

    public function test_with_no_snapshots_there_is_nothing_to_compute(): void
    {
        $this->assertSame(0, app(PriceStatsService::class)->recompute('us'));
        $this->assertSame(0, DB::table('item_stats')->count());
    }

    // ---- prune ----

    public function test_prune_rolls_up_days_it_is_about_to_delete_then_removes_old_data(): void
    {
        config(['ledger.prices.snapshot_days' => 30]);

        $old = gmdate('Y-m-d', time() - 40 * 86400);
        $this->snap($old.' 10:00:00', 7, 100);
        $this->snap($old.' 11:00:00', 7, 300); // never rolled up before the prune
        $recentAt = Utc::sql(time() - 3600);
        $this->snap($recentAt, 7, 555);

        DB::table('price_daily')->insert(['region' => 'us', 'day' => gmdate('Y-m-d', time() - 500 * 86400), 'item_id' => 7, 'floor_min' => 1, 'floor_median' => 1, 'qty_avg' => 1, 'auctions_avg' => 1, 'samples' => 1]);
        DB::table('fetch_runs')->insert([
            ['region' => 'us', 'started_at' => Utc::sql(time() - 40 * 86400), 'status' => 'ok'],
            ['region' => 'us', 'started_at' => Utc::sql(time() - 3600), 'status' => 'ok'],
        ]);

        $result = app(PricePruner::class)->prune();

        $this->assertSame(['snapshots' => 2, 'daily' => 1, 'runs' => 1], $result);
        $this->assertSame([$recentAt], DB::table('price_snapshots')->pluck('taken_at')->all());

        $daily = DB::table('price_daily')->where('day', $old)->first();
        $this->assertNotNull($daily, 'the pruned day must survive as a daily row');
        $this->assertSame(100, (int) $daily->floor_min);
        $this->assertSame(200, (int) $daily->floor_median);
        $this->assertSame(1, DB::table('fetch_runs')->count());
    }

    public function test_prune_with_nothing_old_changes_nothing(): void
    {
        $this->snap(Utc::sql(time() - 3600), 7, 100);

        $this->assertSame(['snapshots' => 0, 'daily' => 0, 'runs' => 0], app(PricePruner::class)->prune());
        $this->assertSame(1, DB::table('price_snapshots')->count());
    }
}
