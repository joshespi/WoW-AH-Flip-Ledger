<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Services\PositionsService;
use App\Services\TradesImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;

class LedgerPagesTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    public function test_guests_and_unverified_users_cannot_see_the_ledger_screens(): void
    {
        foreach (['/dashboard', '/positions'] as $url) {
            $this->get($url)->assertRedirect('/login');
            $this->actingAs(User::factory()->unverified()->create())->get($url)->assertRedirect('/verify-email');
            auth()->logout();
        }
    }

    public function test_the_dashboard_of_a_new_user_points_at_the_two_things_it_needs(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee('Your ledger is empty')
            ->assertSee(route('trades.import.create'), false)
            ->assertSee(route('uploads.index'), false)
            ->assertDontSee('Realized P/L');
    }

    public function test_the_dashboard_shows_the_tiles_positions_and_activity(): void
    {
        $this->actingAs($this->fixtureUser(prices: true))->get('/dashboard')
            ->assertOk()
            ->assertSee('Realized P/L')
            ->assertSee('6,496g')
            ->assertSee('46,521g')
            ->assertSee('1,128g')
            ->assertSee('Shimmersiren')
            ->assertSee('Practically Pork')
            ->assertSee('In profit')
            ->assertSee('Under water')
            ->assertSee('Above typical')
            ->assertSee('Below typical')
            ->assertSee('Recent activity')
            ->assertSee('Concentrated:')
            ->assertSee('1 item has no price yet');
    }

    public function test_the_dashboard_is_in_the_users_timezone(): void
    {
        $user = $this->fixtureUser(prices: true);
        $user->update(['timezone' => 'Pacific/Auckland']);

        $newest = app(PositionsService::class)->forUser($user)->recent[0]['ts'];
        $auckland = Carbon::createFromTimestamp($newest, 'Pacific/Auckland')->format('M j H:i');
        $utc = Carbon::createFromTimestamp($newest, 'UTC')->format('M j H:i');

        $this->assertNotSame($utc, $auckland, 'the test needs a zone that differs from UTC');
        $this->actingAs($user->fresh())->get('/dashboard')->assertSee($auckland)->assertDontSee($utc);
    }

    public function test_the_positions_page_hides_closed_positions_until_asked(): void
    {
        $user = $this->fixtureUser(prices: true);

        $this->actingAs($user)->get('/positions')
            ->assertOk()
            ->assertSee('Shimmersiren')
            ->assertDontSee('Arcane Wyrmfish')
            ->assertSee('Show 2 closed positions');

        $this->actingAs($user)->get('/positions?closed=1')
            ->assertSee('Arcane Wyrmfish')
            ->assertSee('Void-Tempered Leather')
            ->assertSee('Hide closed positions');
    }

    public function test_positions_show_held_beyond_the_tsm_inventory(): void
    {
        $user = $this->fixtureUser(prices: true);

        DB::table('inventory')->where('user_id', $user->id)->delete();
        DB::table('inventory')->insert(['user_id' => $user->id, 'item_id' => self::PORK, 'qty' => 8701, 'taken_at' => '2026-10-28 02:52:29']);

        $this->actingAs($user)->get('/positions')->assertOk()->assertSee('6,701 more than your TSM stock');
    }

    public function test_positions_say_collecting_data_instead_of_showing_early_estimates(): void
    {
        $this->actingAs($this->fixtureUser(prices: true))->get('/positions')
            ->assertSee('collecting data (3/14)')
            ->assertSee('no price yet');
    }

    public function test_positions_page_is_sortable_and_documents_the_estimates(): void
    {
        $this->actingAs($this->fixtureUser(prices: true))->get('/positions')
            ->assertSee('x-data="sortableTable"', false)
            ->assertSee('data-v="15402"', false)
            ->assertSee('our own estimates')
            ->assertSee('Estimate from hourly supply drops');
    }

    public function test_positions_headings_are_keyboard_buttons_with_aria_sort_in_a_sticky_table(): void
    {
        $html = $this->actingAs($this->fixtureUser(prices: true))->get('/positions')->assertOk()->getContent();

        $this->assertStringContainsString('class="data-table data-table-sticky"', $html);
        $this->assertStringContainsString('<caption class="sr-only">', $html);
        $this->assertSame(17, substr_count($html, ':aria-sort="ariaSort('));
        $this->assertSame(17, preg_match_all('/<th scope="col"[^>]*>\s*<button type="button" @click="sort\(\d+/', $html));
    }

    public function test_an_empty_positions_page_offers_the_import(): void
    {
        $this->actingAs(User::factory()->create())->get('/positions')
            ->assertOk()->assertSee('No positions yet')->assertSee(route('trades.import.create'), false);
    }

    public function test_one_user_never_sees_anothers_items_or_numbers(): void
    {
        $alice = $this->fixtureUser(prices: true);
        $bob = User::factory()->create();
        $this->seedItem(5, 'Bobs Special Herb');
        app(TradesImportService::class)->import($bob, "time,item,qty,cost,note\n2026-10-12T10:51Z,i:5,10,10g,\n");

        $page = $this->actingAs($bob)->get('/positions')->assertOk()->assertSee('Bobs Special Herb');
        $page->assertDontSee('Shimmersiren')->assertDontSee('Practically Pork');
        $this->actingAs($bob)->get('/dashboard')->assertDontSee('6,496g')->assertDontSee('Shimmersiren');

        auth()->logout();
        $this->actingAs($alice)->get('/positions')->assertDontSee('Bobs Special Herb');
    }

    public function test_the_header_warns_when_tsm_data_is_stale_and_says_never_when_absent(): void
    {
        $user = User::factory()->create(['stale_minutes' => 30]);

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('Game saved')
            ->assertSee('never')
            ->assertSee('Upload your data')
            ->assertDontSee('Stale: /reload');

        $this->upload($user, time() - 10 * 60);
        $this->actingAs($user)->get('/dashboard')->assertSee('10 min ago')->assertDontSee('Stale: /reload');

        Upload::where('user_id', $user->id)->delete();
        $this->upload($user, time() - 3 * 3600);
        $this->actingAs($user)->get('/dashboard')->assertSee('3 h ago')->assertSee('Stale: /reload in game, then upload');
    }

    public function test_the_stale_threshold_is_per_user(): void
    {
        $user = User::factory()->create(['stale_minutes' => 240]);
        $this->upload($user, time() - 3 * 3600);

        $this->actingAs($user)->get('/dashboard')->assertDontSee('Stale: /reload');

        $user->update(['stale_minutes' => 60]);
        $this->actingAs($user->fresh())->get('/dashboard')->assertSee('Stale: /reload');
    }

    public function test_the_header_shows_blizzards_snapshot_age_from_the_newest_good_fetch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertSee('not fetched yet');

        // Fetched 10 minutes ago; Blizzard's snapshot itself is two hours old.
        $this->seedFetchRun('ok', time() - 600, time() - 7200);
        $this->actingAs($user)->get('/dashboard')->assertSee('2 h ago')->assertDontSee('not fetched yet')
            ->assertDontSee('Prices stale')->assertDontSee('Price fetch failing');
    }

    public function test_unchanged_fetches_count_as_good_and_other_regions_do_not(): void
    {
        $user = User::factory()->create();
        $this->seedFetchRun('ok', time() - 600, time() - 7200, 'eu');

        $this->actingAs($user)->get('/dashboard')->assertSee('not fetched yet');

        $this->seedFetchRun('unchanged', time() - 300, time() - 3 * 86400);
        $this->actingAs($user)->get('/dashboard')->assertSee('3 d ago');
    }

    public function test_prices_are_flagged_when_the_last_good_fetch_is_old_or_the_latest_failed(): void
    {
        $user = User::factory()->create();

        $this->seedFetchRun('ok', time() - 4 * 3600, time() - 4 * 3600);
        $this->actingAs($user)->get('/dashboard')->assertSee('Prices stale');

        $this->seedFetchRun('ok', time() - 600, time() - 900);
        $this->actingAs($user)->get('/dashboard')->assertDontSee('Prices stale');

        $this->seedFetchRun('failed', time() - 60);
        $this->actingAs($user)->get('/dashboard')->assertSee('Price fetch failing')->assertSee('15 min ago');
    }

    private function upload(User $user, int $mtime): void
    {
        Upload::create([
            'user_id' => $user->id, 'source' => 'manual', 'file_mtime' => $mtime, 'received_at' => now(),
            'bytes' => 1, 'status' => Upload::STATUS_OK, 'counts' => [],
        ]);
    }

    public function test_the_freshness_bar_renders_nothing_for_a_guest(): void
    {
        $this->assertSame('', trim(Blade::render('<x-freshness-bar />')));
    }

    public function test_the_freshness_bar_prefers_the_files_own_time_over_when_it_arrived(): void
    {
        $user = User::factory()->create();
        // Arrived just now, but TSM wrote the file two hours ago.
        $this->upload($user, time() - 7200);

        $this->actingAs($user)->get('/dashboard')->assertSee('2 h ago');
    }
}
