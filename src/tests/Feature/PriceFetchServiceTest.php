<?php

namespace Tests\Feature;

use App\Models\FetchRun;
use App\Services\Blizzard\BlizzardClient;
use App\Services\PriceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesBlizzard;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class PriceFetchServiceTest extends TestCase
{
    use FakesBlizzard, RefreshDatabase, SeedsMarketData;

    private function fetch(bool $force = false): FetchRun
    {
        return app(PriceFetchService::class)->fetch('us', $force);
    }

    private function snapshots(): int
    {
        return DB::table('price_snapshots')->count();
    }

    private function leftoverTempFiles(): int
    {
        return count(glob(sys_get_temp_dir().'/wfl-commodities-*') ?: []);
    }

    public function test_a_fetch_stores_one_snapshot_stamped_with_blizzards_time(): void
    {
        $this->fakeBlizzard();
        $before = $this->leftoverTempFiles();

        $run = $this->fetch();

        $this->assertSame(FetchRun::STATUS_OK, $run->status);
        $this->assertSame(4, $run->items);
        $this->assertSame(10, $run->auctions);
        $this->assertSame('2026-10-26 12:00:00', $run->last_modified->format('Y-m-d H:i:s'));
        $this->assertNotNull($run->finished_at);

        $this->assertSame(4, $this->snapshots());
        $this->assertEquals(
            ['region' => 'us', 'taken_at' => '2026-10-26 12:00:00', 'item_id' => 238371, 'floor' => 384000, 'quantity' => 3500, 'num_auctions' => 3],
            (array) DB::table('price_snapshots')->where('item_id', 238371)->first(),
        );
        $this->assertSame($before, $this->leftoverTempFiles(), 'the downloaded file must be deleted');
    }

    public function test_unseen_items_are_added_to_the_catalog_as_unnamed_commodities(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(242639, 'Practically Pork', commodity: false);

        $this->fetch();

        $this->assertSame(4, DB::table('items')->count());
        $this->assertEquals(['name' => null, 'name_source' => 'none', 'is_commodity' => 1], (array) DB::table('items')->where('id', 238371)->first(['name', 'name_source', 'is_commodity']));
        $this->assertEquals(['name' => 'Practically Pork', 'name_source' => 'tsm', 'is_commodity' => 1], (array) DB::table('items')->where('id', 242639)->first(['name', 'name_source', 'is_commodity']), 'an existing name is kept and the item is flagged a commodity');
    }

    public function test_the_same_blizzard_snapshot_is_not_stored_twice_even_if_the_server_ignores_if_modified_since(): void
    {
        $this->fakeBlizzard();

        $this->fetch();
        $second = $this->fetch(); // the fake answers 200 with the same Last-Modified again

        $this->assertSame(FetchRun::STATUS_UNCHANGED, $second->status);
        $this->assertSame(4, $this->snapshots());
        $this->assertSame('2026-10-26 12:00:00', $second->last_modified->format('Y-m-d H:i:s'));
    }

    public function test_the_next_fetch_asks_if_modified_since_the_last_snapshot(): void
    {
        $this->fakeBlizzard();

        $this->fetch();
        $this->fetch();

        $commodityCalls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'commodities'))->values();
        $this->assertCount(2, $commodityCalls);
        $this->assertFalse($commodityCalls[0][0]->hasHeader('If-Modified-Since'));
        $this->assertTrue($commodityCalls[1][0]->hasHeader('If-Modified-Since', self::LAST_MODIFIED));
    }

    public function test_a_304_is_recorded_as_unchanged_and_keeps_the_conditional_date(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => self::LAST_MODIFIED])
            ->push('', 304)
            ->push('', 304)]);

        $this->fetch();
        $second = $this->fetch();
        $third = $this->fetch();

        $this->assertSame(FetchRun::STATUS_UNCHANGED, $second->status);
        $this->assertSame(FetchRun::STATUS_UNCHANGED, $third->status);
        $this->assertSame(4, $this->snapshots());

        $calls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'commodities'))->values();
        $this->assertTrue($calls[2][0]->hasHeader('If-Modified-Since', self::LAST_MODIFIED), 'unchanged runs must not lose the date');
    }

    public function test_a_newer_blizzard_snapshot_adds_a_second_set_of_rows(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => 'Mon, 26 Oct 2026 12:00:00 GMT'])
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => 'Mon, 26 Oct 2026 13:00:00 GMT'])]);

        $this->fetch();
        $second = $this->fetch();

        $this->assertSame(FetchRun::STATUS_OK, $second->status);
        $this->assertSame(8, $this->snapshots());
        $this->assertSame(2, DB::table('price_snapshots')->where('item_id', 238371)->count());
    }

    public function test_force_reprocesses_the_current_snapshot_without_duplicating_rows(): void
    {
        $this->fakeBlizzard();

        $this->fetch();
        $forced = $this->fetch(force: true);

        $this->assertSame(FetchRun::STATUS_OK, $forced->status);
        $this->assertSame(4, $this->snapshots());

        $calls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'commodities'))->values();
        $this->assertFalse($calls[1][0]->hasHeader('If-Modified-Since'), '--force must not send If-Modified-Since');
    }

    public function test_a_failed_fetch_is_recorded_and_leaves_the_last_good_data_alone(): void
    {
        $good = $this->commoditiesBody();
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($good, 200, ['Last-Modified' => 'Mon, 26 Oct 2026 12:00:00 GMT'])
            ->push(substr($good, 0, 300), 200, ['Last-Modified' => 'Mon, 26 Oct 2026 13:00:00 GMT'])
            ->push('Service Unavailable', 503)]);

        $this->fetch();
        $itemsBefore = DB::table('items')->count();

        $truncated = $this->fetch();
        $down = $this->fetch();

        foreach ([$truncated, $down] as $run) {
            $this->assertSame(FetchRun::STATUS_FAILED, $run->status);
            $this->assertNotEmpty($run->error);
            $this->assertStringNotContainsString('test-client', $run->error);
        }
        $this->assertStringContainsString('not valid commodity data', $truncated->error);
        $this->assertStringContainsString('HTTP 503', $down->error);

        $this->assertSame(4, $this->snapshots(), 'a failed fetch must not add or remove rows');
        $this->assertSame($itemsBefore, DB::table('items')->count());
        $this->assertSame(1, FetchRun::where('status', FetchRun::STATUS_OK)->count());
    }

    public function test_a_failed_fetch_does_not_poison_the_next_conditional_request(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => 'Mon, 26 Oct 2026 12:00:00 GMT'])
            ->push('nope', 500)
            ->push('', 304)]);

        $this->fetch();
        $this->fetch();
        $third = $this->fetch();

        $this->assertSame(FetchRun::STATUS_UNCHANGED, $third->status);
        $calls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'commodities'))->values();
        $this->assertTrue($calls[2][0]->hasHeader('If-Modified-Since', self::LAST_MODIFIED));
    }

    public function test_without_a_last_modified_header_the_snapshot_is_stamped_with_the_fetch_time(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::response($this->commoditiesBody(), 200)]);

        $run = $this->fetch();

        $this->assertSame(FetchRun::STATUS_OK, $run->status);
        $this->assertEqualsWithDelta(time(), $run->last_modified->timestamp, 5);
    }

    public function test_unexpected_errors_are_contained_and_not_shown_to_users(): void
    {
        $this->mock(BlizzardClient::class)
            ->shouldReceive('commodities')->andThrow(new \RuntimeException('internal secret detail'));

        $run = $this->fetch();

        $this->assertSame(FetchRun::STATUS_FAILED, $run->status);
        $this->assertStringNotContainsString('internal secret detail', $run->error);
        $this->assertStringContainsString('application log', $run->error);
    }

    public function test_a_run_left_running_by_a_crash_is_marked_failed_by_the_next_fetch(): void
    {
        $this->fakeBlizzard();
        $stale = FetchRun::create(['region' => 'us', 'started_at' => now()->subHours(3), 'status' => FetchRun::STATUS_RUNNING]);
        $recent = FetchRun::create(['region' => 'us', 'started_at' => now()->subMinutes(5), 'status' => FetchRun::STATUS_RUNNING]);

        $this->fetch();

        $this->assertSame(FetchRun::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(FetchRun::STATUS_RUNNING, $recent->fresh()->status, 'a run that may still be going is left alone');
    }

    public function test_every_attempt_is_recorded(): void
    {
        $this->fakeBlizzard();

        $this->fetch();
        $this->fetch();

        $this->assertSame(['ok', 'unchanged'], FetchRun::orderBy('id')->pluck('status')->all());
    }
}
