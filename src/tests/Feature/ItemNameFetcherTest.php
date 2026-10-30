<?php

namespace Tests\Feature;

use App\Services\ItemNameFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesBlizzard;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class ItemNameFetcherTest extends TestCase
{
    use FakesBlizzard, RefreshDatabase, SeedsMarketData;

    private function row(int $id): object
    {
        return DB::table('items')->where('id', $id)->first();
    }

    private function fetch(int $limit = 100): array
    {
        return app(ItemNameFetcher::class)->fetch('us', $limit, pauseMs: 0);
    }

    public function test_it_names_commodities_and_records_quality_and_the_lookup_time(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(238371);

        $r = $this->fetch();

        $this->assertSame(['updated' => 1, 'not_found' => 0, 'stopped' => null], $r);
        $row = $this->row(238371);
        $this->assertSame('Arcane Wyrmfish', $row->name);
        $this->assertSame('blizzard', $row->name_source);
        $this->assertSame('EPIC', $row->quality);
        $this->assertNotNull($row->fetched_at);
    }

    public function test_blizzards_name_replaces_a_tsm_name_but_never_the_other_way_round(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(238371, 'Arcane Wyrmfish (from an upload)', 'tsm');
        $this->seedItem(242639, 'Practically Pork', 'blizzard', fetchedAt: '2026-10-01 00:00:00');

        $this->fetch();

        $this->assertSame(['Arcane Wyrmfish', 'blizzard'], [$this->row(238371)->name, $this->row(238371)->name_source]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/item/242639'));
    }

    public function test_items_blizzard_does_not_know_are_marked_so_they_are_not_retried(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(5, 'Upload Name', 'tsm');

        $first = $this->fetch();
        $second = $this->fetch();

        $this->assertSame(['updated' => 0, 'not_found' => 1, 'stopped' => null], $first);
        $this->assertSame(0, $second['not_found']);
        $this->assertSame('Upload Name', $this->row(5)->name, 'a 404 must not erase the TSM name');
        $this->assertNotNull($this->row(5)->fetched_at);
        Http::assertSentCount(1 + 1); // one token, one lookup, nothing on the second run
    }

    public function test_unnamed_items_come_first_and_the_limit_is_respected(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(238365, 'Old TSM Name', 'tsm');   // lowest id, but already has a name
        $this->seedItem(238371);
        $this->seedItem(242639);

        $r = $this->fetch(limit: 2);

        $this->assertSame(2, $r['updated']);
        $this->assertSame('Arcane Wyrmfish', $this->row(238371)->name);
        $this->assertSame('Practically Pork', $this->row(242639)->name);
        $this->assertSame('Old TSM Name', $this->row(238365)->name, 'named items wait their turn');
    }

    public function test_non_commodities_are_left_alone(): void
    {
        $this->fakeBlizzard();
        $this->seedItem(238371, commodity: false);

        $this->assertSame(0, $this->fetch()['updated']);
        Http::assertNothingSent();
    }

    public function test_a_server_failure_stops_the_run_and_leaves_the_rest_for_next_time(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::sequence()
            ->push(['name' => 'Arcane Wyrmfish', 'quality' => ['type' => 'EPIC']])
            ->push('down', 503)]);
        $this->seedItem(238365);
        $this->seedItem(238371);
        $this->seedItem(242639);

        $r = $this->fetch();

        $this->assertSame(1, $r['updated']);
        $this->assertStringContainsString('HTTP 503', $r['stopped']);
        $this->assertNull($this->row(238371)->fetched_at, 'the item that hit the error is retried next run');
        $this->assertNull($this->row(242639)->fetched_at);
    }

    public function test_names_from_blizzard_are_sanitized_too(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::response(['name' => "  Spaced\x01 Out\n", 'quality' => ['type' => 'RARE']])]);
        $this->seedItem(1);

        $this->fetch();

        $this->assertSame('Spaced Out', $this->row(1)->name);
    }

    public function test_a_lookup_with_no_usable_name_keeps_the_old_one_but_still_counts_as_fetched(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::response(['quality' => ['type' => 'RARE']])]);
        $this->seedItem(1, 'From TSM', 'tsm');

        $this->fetch();

        $this->assertSame('From TSM', $this->row(1)->name);
        $this->assertSame('tsm', $this->row(1)->name_source);
        $this->assertNotNull($this->row(1)->fetched_at);
    }
}
