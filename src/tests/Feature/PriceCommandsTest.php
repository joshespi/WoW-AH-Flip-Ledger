<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IngestService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesBlizzard;
use Tests\Support\SeedsMarketData;
use Tests\Support\TsmFile;
use Tests\TestCase;

class PriceCommandsTest extends TestCase
{
    use FakesBlizzard, RefreshDatabase, SeedsMarketData;

    public function test_without_credentials_the_commands_refuse_and_make_no_requests(): void
    {
        config(['services.blizzard.client_id' => null, 'services.blizzard.client_secret' => null]);
        Http::fake();

        foreach (['prices:fetch', 'prices:names', 'prices:probe'] as $command) {
            $this->artisan($command)->expectsOutputToContain('Blizzard credentials are not set')->assertFailed();
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('fetch_runs')->count());
    }

    public function test_fetch_stores_the_snapshot_rolls_up_today_and_computes_stats(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => fn () => Http::response($this->commoditiesBody(), 200, ['Last-Modified' => gmdate('D, d M Y H:i:s', time() - 600).' GMT'])]);

        // One line carries both facts, so it can only satisfy one expectation.
        $this->artisan('prices:fetch')
            ->expectsOutputToContain('Stored 4 items from 10 auctions (Blizzard snapshot')
            ->assertSuccessful();

        $this->assertSame(4, DB::table('price_snapshots')->count());
        $this->assertSame(4, DB::table('price_daily')->count());
        $this->assertSame(4, DB::table('item_stats')->count());
        $this->assertSame(384000, (int) DB::table('item_stats')->where('item_id', 238371)->value('floor'));
    }

    public function test_a_repeat_fetch_says_nothing_new_and_a_failure_exits_nonzero(): void
    {
        // One fake answering in turn: a fresh snapshot, then 304, then an outage.
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => self::LAST_MODIFIED])
            ->push('', 304)
            ->push('down', 503)]);

        $this->artisan('prices:fetch')->assertSuccessful();
        $this->artisan('prices:fetch')->expectsOutputToContain('has not published a new snapshot')->assertSuccessful();
        $this->artisan('prices:fetch --force')->expectsOutputToContain('Fetch failed: Blizzard returned HTTP 503')->assertFailed();
    }

    public function test_names_stats_rollup_and_prune_run(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(238371);

        $this->artisan('prices:names --limit=5')->expectsOutputToContain('Named 1 items')->assertSuccessful();
        $this->artisan('prices:stats')->expectsOutputToContain('No snapshots yet')->assertSuccessful();
        $this->artisan('prices:rollup --days=1 --stats')->expectsOutputToContain('Rolled up 0 item-days')->assertSuccessful();
        $this->artisan('prices:prune')->expectsOutputToContain('Pruned 0 snapshots')->assertSuccessful();
    }

    public function test_names_reports_an_early_stop(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::response('down', 503)]);
        $this->seedItem(1);

        $this->artisan('prices:names')->expectsOutputToContain('Stopped early')->assertFailed();
    }

    public function test_probe_reports_the_live_api_checks_and_writes_nothing(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push($this->commoditiesBody(), 200, ['Last-Modified' => self::LAST_MODIFIED, 'Content-Length' => '999'])
            ->push('', 304)]);

        $this->artisan('prices:probe')
            ->expectsOutputToContain('got a token')
            ->expectsOutputToContain('parsed into 4 distinct items')
            ->expectsOutputToContain('304 Not Modified')
            ->expectsOutputToContain('is "Arcane Wyrmfish" (EPIC)')
            ->expectsOutputToContain('All checks passed.')
            ->assertSuccessful();

        foreach (['price_snapshots', 'price_daily', 'item_stats', 'fetch_runs', 'items'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "probe wrote to {$table}");
        }
    }

    public function test_probe_flags_a_server_that_ignores_if_modified_since(): void
    {
        $this->fakeBlizzard();

        $this->artisan('prices:probe')
            ->expectsOutputToContain('If-Modified-Since is NOT honored')
            ->expectsOutputToContain('Checks finished with notes above.')
            ->assertSuccessful();
    }

    public function test_probe_reports_a_credential_failure(): void
    {
        $this->fakeBlizzard([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $this->artisan('prices:probe')->expectsOutputToContain('refused the credentials')->assertFailed();
    }

    public function test_the_price_jobs_are_scheduled_and_only_run_when_credentials_exist(): void
    {
        // withSchedule() callbacks run when the console kernel first resolves the schedule, which
        // `schedule:list` does; before that the event list is empty.
        $this->artisan('schedule:list')->assertSuccessful();
        $all = collect(app(Schedule::class)->events());
        $find = fn (string $name): ?Event => $all->first(fn (Event $e) => str_contains($e->command, $name));

        foreach (['prices:fetch', 'prices:names', 'prices:prune'] as $name) {
            $event = $find($name);
            $this->assertNotNull($event, "{$name} is not scheduled");
            $this->assertTrue($event->onOneServer, "{$name} must be onOneServer()");
            $this->assertTrue($event->withoutOverlapping, "{$name} must be withoutOverlapping()");
        }

        config(['services.blizzard.client_id' => null, 'services.blizzard.client_secret' => null]);
        $this->assertFalse($find('prices:fetch')->filtersPass($this->app));
        $this->assertFalse($find('prices:names')->filtersPass($this->app));
        $this->assertTrue($find('prices:prune')->filtersPass($this->app), 'pruning needs no credentials');

        $this->blizzardConfigured();
        $this->assertTrue($find('prices:fetch')->filtersPass($this->app));
    }

    public function test_a_tsm_upload_names_items_the_price_fetch_created_without_a_name(): void
    {
        $this->fakeBlizzard();
        $this->artisan('prices:fetch')->assertSuccessful();
        $this->assertNull(DB::table('items')->where('id', 238371)->value('name'));

        $user = User::factory()->create();
        $file = TsmFile::make(
            sales: [['i:238371', 5, 200, TsmFile::T0, 'Auction']],
            names: ['i:238371' => 'Arcane Wyrmfish'],
        );

        $upload = app(IngestService::class)->ingest($user, $file, 'manual');

        $this->assertSame(1, $upload->counts['names_added']);
        $this->assertEquals(['Arcane Wyrmfish', 'tsm', 1], array_values((array) DB::table('items')->where('id', 238371)->first(['name', 'name_source', 'is_commodity'])));
    }
}
