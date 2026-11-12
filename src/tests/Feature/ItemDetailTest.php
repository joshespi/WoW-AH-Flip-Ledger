<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ItemDetailService;
use App\Services\PositionsService;
use App\Services\TradesService;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsMarketData;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class ItemDetailTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;
    use SeedsUserRows;

    private const T = 1_791_823_860; // 2026-10-12 16:51 UTC

    private function user(): User
    {
        $this->seedItem(100, 'Practically Pork');

        return User::factory()->create(['timezone' => 'UTC']);
    }

    private function sale(User $user, int $at, int $qty, int $unit, int $item = 100): void
    {
        $this->seedSale($user, $item, $qty, $unit, $at);
    }

    private function listing(string $table, User $user, int $at, int $qty): void
    {
        DB::table($table)->insert([
            'user_id' => $user->id, 'realm' => 'R', 'item_id' => 100, 'qty' => $qty,
            'at' => Utc::sql($at), 'dedupe_key' => sha1("$table$at$qty"),
        ]);
    }

    public function test_timeline_and_running_position_match_the_ledger(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);
        $trades->add($user, 100, 100, 100_000, self::T, null);          // 1000/unit
        $trades->add($user, 100, 100, 200_000, self::T + 100, null);    // 2000/unit -> avg 1500
        $this->sale($user, self::T - 50, 10, 999);                      // before first buy: untracked
        $this->sale($user, self::T + 200, 50, 2000);                    // sells 50 @ avg 1500
        $this->sale($user, self::T + 300, 400, 2000);                   // only 150 held: 250 own stock
        $this->listing('tsm_expired', $user, self::T + 250, 5);
        $this->listing('tsm_cancelled', $user, self::T + 260, 7);

        $d = app(ItemDetailService::class)->forUser($user, 100);

        // Newest first.
        $this->assertSame(['sale', 'cancelled', 'expired', 'sale', 'buy', 'buy', 'sale'], array_column($d['timeline'], 'kind'));
        $this->assertSame(7, $d['timeline_total']);
        $this->assertSame([75_000, null, null, 25_000, null, null, null], array_column($d['timeline'], 'pnl'));
    }

    public function test_position_agrees_with_the_ledger_service(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);
        $trades->add($user, 100, 100, 100_000, self::T, null);
        $trades->add($user, 100, 100, 200_000, self::T + 100, null);
        $this->sale($user, self::T - 50, 10, 999);
        $this->sale($user, self::T + 200, 50, 2000);
        $this->sale($user, self::T + 300, 400, 2000);

        $d = app(ItemDetailService::class)->forUser($user, 100);
        $row = collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 100);

        $this->assertSame($row->held, $d['position']['held']);
        $this->assertSame($row->realized, $d['position']['realized']);
        $this->assertSame(100_000, $d['position']['realized']);
        $this->assertSame(0, $d['position']['held']);
        $this->assertSame(250, $d['position']['own_qty']);

        // Oldest first: the sale before the first buy is not in the ledger.
        $this->assertSame([false, true, true, true, true], array_column(array_reverse($d['timeline']), 'tracked'));
    }

    public function test_adjustments_agree_with_the_ledger_service(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);
        $trades->add($user, 100, 100, 100_000, self::T, null);
        $this->sale($user, self::T + 200, 40, 2000);
        $trades->adjust($user, 100, 30, self::T + 300);

        $d = app(ItemDetailService::class)->forUser($user, 100);
        $row = collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 100);

        $this->assertSame(30, $row->held);
        $this->assertSame($row->held, $d['position']['held']);
        $this->assertSame($row->realized, $d['position']['realized']);
        $this->assertSame(['adjust', 'sale', 'buy'], array_column($d['timeline'], 'kind'));
        $this->assertSame([100, 60, 30], array_column($d['steps'], 'held'));
    }

    public function test_write_offs_agree_with_the_ledger_service(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);
        $trades->add($user, 100, 100, 100_000, self::T, null);
        $this->sale($user, self::T + 200, 40, 2000);
        $trades->correctHeld($user, 100, 30, 'writeoff');

        $d = app(ItemDetailService::class)->forUser($user, 100);
        $row = collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 100);

        $this->assertSame(30, $row->held);
        $this->assertSame(40_000 - 30_000, $row->realized);
        $this->assertSame($row->realized, $d['position']['realized']);
        $this->assertSame($row->held, $d['position']['held']);
        $this->assertSame(['writeoff', 'sale', 'buy'], array_column($d['timeline'], 'kind'));
        $this->assertSame(-30_000, $d['timeline'][0]['pnl']);
    }

    public function test_steps_follow_each_buy_and_sale(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);
        $trades->add($user, 100, 100, 100_000, self::T, null);
        $trades->add($user, 100, 100, 200_000, self::T + 100, null);
        $this->sale($user, self::T + 200, 50, 2000);

        $d = app(ItemDetailService::class)->forUser($user, 100);

        $this->assertSame([100, 200, 150], array_column($d['steps'], 'held'));
        $this->assertEqualsWithDelta(1500.0, $d['steps'][1]['avg'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $d['steps'][2]['avg'], 0.001);
        $this->assertSame(25_000, $d['position']['realized']);
        $this->assertSame(150, $d['position']['held']);
    }

    public function test_market_numbers_are_gated_and_the_series_is_built(): void
    {
        $user = $this->user();
        $this->seedStats([100 => ['floor' => 700, 'recent' => 800, 'typical' => 1000, 'hist60' => 900, 'sold' => 40, 'days' => 5]]);

        foreach ([['2026-10-10', 900, 800], ['2026-10-11', 950, 850]] as [$day, $median, $min]) {
            DB::table('price_daily')->insert(['region' => 'us', 'day' => $day, 'item_id' => 100, 'floor_min' => $min, 'floor_median' => $median, 'qty_avg' => 1, 'auctions_avg' => 1, 'samples' => 24]);
        }

        $d = app(ItemDetailService::class)->forUser($user, 100);

        $this->assertSame(700, $d['market']['floor']);
        $this->assertFalse($d['market']['ready']);
        $this->assertNull($d['market']['typical']);
        $this->assertNull($d['market']['sold']);
        $this->assertSame([900, 950, 700], array_column($d['daily'], 'median'));
    }

    public function test_unknown_item_is_a_404_and_priced_item_is_not(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/items/555')->assertNotFound();
        $this->get('/items/abc')->assertNotFound();
        $this->get('/items/100')->assertOk()->assertSee('Practically Pork');
    }

    public function test_page_shows_the_users_own_history_only(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        app(TradesService::class)->add($user, 100, 5, 5000, self::T, null);
        $this->sale($other, self::T + 10, 7, 4321);
        $this->listing('tsm_expired', $other, self::T + 20, 3);

        $this->actingAs($user)->get('/items/100')->assertOk()->assertSee('Bought')->assertDontSee('Expired')->assertDontSee('Sold');
    }

    public function test_page_renders_with_a_full_history_and_prices(): void
    {
        $user = $this->user();
        app(TradesService::class)->add($user, 100, 100, 100_000, self::T, null);
        $this->sale($user, self::T + 200, 50, 2000);
        $this->listing('tsm_cancelled', $user, self::T + 260, 7);
        $this->seedStats([100 => ['floor' => 700, 'recent' => 800, 'typical' => 1000, 'hist60' => 900, 'sold' => 40, 'days' => 20]]);

        $this->actingAs($user)->get('/items/100')->assertOk()->assertSee('Price history')->assertSee('Cancelled')->assertSee('itemChart', false);
    }

    public function test_other_screens_link_to_the_item(): void
    {
        $user = $this->user();
        app(TradesService::class)->add($user, 100, 5, 5000, self::T, null);

        $this->actingAs($user)->get('/trades')->assertSee(route('items.show', 100), false);
        $this->get('/positions')->assertSee(route('items.show', 100), false);
    }
}
