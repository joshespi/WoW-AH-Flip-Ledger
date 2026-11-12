<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PositionRow;
use App\Services\PositionsService;
use App\Services\TradesImportService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLedgerFixture;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class PositionsServiceTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;
    use SeedsUserRows;

    /**
     * @return array<int, PositionRow>
     */
    private function rows(User $user): array
    {
        $rows = [];

        foreach (app(PositionsService::class)->forUser($user)->positions as $p) {
            $rows[$p->itemId] = $p;
        }

        return $rows;
    }

    public function test_a_user_with_no_trades_gets_an_empty_portfolio(): void
    {
        $p = app(PositionsService::class)->forUser(User::factory()->create());

        $this->assertTrue($p->isEmpty());
        $this->assertSame([], $p->recent);
        $this->assertNull($p->concentration);
        $this->assertSame(0, $p->summary['stock_cost']);
    }

    public function test_summary_matches_the_legacy_tools_known_totals(): void
    {
        $user = $this->fixtureUser();

        $s = app(PositionsService::class)->forUser($user)->summary;

        $this->assertSame('6,496g 53s 80c', Money::fmt($s['realized']));
        $this->assertSame('46,521g 26s 64c', Money::fmt($s['stock_cost']));
        $this->assertSame('1,128g 73s 30c', Money::fmt($s['own_stock_sales']));
    }

    public function test_known_answer_rows_from_the_fixture(): void
    {
        $user = $this->fixtureUser();
        $rows = $this->rows($user);

        $this->assertSame('7,203g 07s 00c', Money::fmt($rows[self::WYRMFISH]->realized));
        $this->assertTrue($rows[self::WYRMFISH]->closed());

        $this->assertSame(424, $rows[self::SWARMER]->held);
        $this->assertSame('20s 00c', Money::fmt($rows[self::SWARMER]->avgCost));
        $this->assertSame('-1,488g 16s 20c', Money::fmt($rows[self::SWARMER]->realized));

        $this->assertSame(15402, $rows[self::PORK]->held);
        $this->assertSame('50s 79c', Money::fmt($rows[self::PORK]->avgCost));
        $this->assertSame('53s 46c', Money::fmt($rows[self::PORK]->breakEven));

        $this->assertSame(42, $rows[self::GLIMMERLINE]->soldQty);
        $this->assertSame('66g 89s 14c', Money::fmt($rows[self::GLIMMERLINE]->realized));

        $this->assertSame(1000, $rows[self::SHIMMERSIREN]->held);
        $this->assertSame('30g 10s 00c', Money::fmt($rows[self::SHIMMERSIREN]->avgCost));
    }

    public function test_held_beyond_the_tsm_inventory_snapshot_is_flagged(): void
    {
        $user = $this->fixtureUser();

        DB::table('inventory')->where('user_id', $user->id)->delete();
        $rows = $this->rows($user);
        $this->assertNull($rows[self::PORK]->unaccounted, 'no snapshot, nothing to compare');

        $this->seedInventory($user, [self::PORK => 8701, self::SWARMER => 500], '2026-10-28 02:52:29');
        $rows = $this->rows($user);

        $this->assertSame(15402 - 8701, $rows[self::PORK]->unaccounted);
        $this->assertNull($rows[self::SWARMER]->unaccounted, 'more in stock than the ledger holds is own stock, not a gap');
        $this->assertSame(1000, $rows[self::SHIMMERSIREN]->unaccounted, 'held but absent from a snapshot that exists');
    }

    public function test_stock_match_removes_the_gap_to_the_tsm_snapshot_without_touching_pnl(): void
    {
        $user = $this->fixtureUser();
        DB::table('inventory')->where('user_id', $user->id)->delete();
        $this->seedInventory($user, [self::PORK => 8701], '2026-10-28 02:52:29');
        $before = $this->rows($user);

        $this->artisan('stock:match', ['--user' => $user->id])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, DB::table('trades')->where('side', 'adjust')->count());

        $this->artisan('stock:match', ['--user' => $user->id, '--apply' => true])->assertSuccessful();
        $after = $this->rows($user);

        $this->assertSame(8701, $after[self::PORK]->held);
        $this->assertNull($after[self::PORK]->unaccounted);
        $this->assertSame($before[self::PORK]->realized, $after[self::PORK]->realized);
        $this->assertEqualsWithDelta($before[self::PORK]->avgCost, $after[self::PORK]->avgCost, 0.01);

        $this->artisan('stock:match', ['--user' => $user->id, '--apply' => true])->expectsOutputToContain('Nothing to match')->assertSuccessful();
    }

    public function test_open_positions_come_first_largest_cost_first(): void
    {
        $user = $this->fixtureUser();

        $order = array_map(fn (PositionRow $p) => $p->itemId, app(PositionsService::class)->forUser($user)->positions);

        $this->assertSame(self::SHIMMERSIREN, $order[0]);
        $this->assertSame(self::PORK, $order[1]);
        $closed = array_slice($order, -2);
        $this->assertEqualsCanonicalizing([self::WYRMFISH, self::LEATHER], $closed);
    }

    public function test_status_chips_follow_floor_against_break_even_and_typical_price(): void
    {
        $user = $this->fixtureUser(prices: true);
        $rows = $this->rows($user);

        // floor x 0.95 against average cost
        $this->assertSame('profit', $rows[self::SHIMMERSIREN]->status);
        $this->assertSame('underwater', $rows[self::PORK]->status);
        $this->assertSame('profit', $rows[self::SWARMER]->status);

        // floor / typical: 330k / 250k = 1.32 rich; 5.2k / 7k = 0.74 cheap
        $this->assertSame('above', $rows[self::SHIMMERSIREN]->market);
        $this->assertSame('below', $rows[self::PORK]->market);
        $this->assertNull($rows[self::BLOOMLINE]->market, 'floor / typical of 1.03 is neither');

        // floor x 0.95 / average cost - 1
        $this->assertEqualsWithDelta(313_500 / 301_000 - 1, $rows[self::SHIMMERSIREN]->floorVsBreakEven, 0.0001);
        $this->assertEqualsWithDelta(5_200 * 0.95 / $rows[self::PORK]->avgCost - 1, $rows[self::PORK]->floorVsBreakEven, 0.0001);
    }

    public function test_derived_metrics_stay_hidden_until_enough_history(): void
    {
        $user = $this->fixtureUser(prices: true);
        $rows = $this->rows($user);

        $swarmer = $rows[self::SWARMER];
        $this->assertFalse($swarmer->statsReady);
        $this->assertSame(5, $swarmer->daysOfData);
        $this->assertSame(3_000, $swarmer->floor, 'the exact floor is always shown');
        $this->assertNull($swarmer->recent);
        $this->assertNull($swarmer->typical);
        $this->assertNull($swarmer->hist60);
        $this->assertNull($swarmer->soldPerDay);
        $this->assertNull($swarmer->daysToSell);
        $this->assertNull($swarmer->market);
        $this->assertNull($swarmer->floorVsTypical);

        $pork = $rows[self::PORK];
        $this->assertTrue($pork->statsReady);
        $this->assertSame(7_000, $pork->typical);
        $this->assertSame(40_000, $pork->soldPerDay);
    }

    public function test_the_threshold_is_the_configured_minimum_days(): void
    {
        $user = $this->fixtureUser();
        $this->seedStats([self::PORK => ['floor' => 5_200, 'days' => 13, 'typical' => 7_000, 'sold' => 1]]);
        $this->assertFalse($this->rows($user)[self::PORK]->statsReady);

        $this->seedStats([self::PORK => ['floor' => 5_200, 'days' => 14, 'typical' => 7_000, 'sold' => 1]]);
        $this->assertTrue($this->rows($user)[self::PORK]->statsReady);
    }

    public function test_closed_positions_show_no_floor_but_keep_estimates_and_realized_pl(): void
    {
        $user = $this->fixtureUser(prices: true);

        $wyrmfish = $this->rows($user)[self::WYRMFISH];

        $this->assertTrue($wyrmfish->closed());
        $this->assertNull($wyrmfish->floor);
        $this->assertNull($wyrmfish->status);
        $this->assertNull($wyrmfish->market);
        $this->assertNull($wyrmfish->daysToSell);
        $this->assertSame(420_000, $wyrmfish->typical);
        $this->assertSame('7,203g 07s 00c', Money::fmt($wyrmfish->realized));
    }

    public function test_items_without_a_price_have_no_status_and_are_counted_as_unpriced(): void
    {
        $user = $this->fixtureUser(prices: true);

        $root = $this->rows($user)[self::ROOT];
        $this->assertNull($root->floor);
        $this->assertNull($root->status);
        $this->assertNull($root->valueAtFloor);
        $this->assertNull($root->unrealized);
        $this->assertFalse($root->statsReady);

        $s = app(PositionsService::class)->forUser($user)->summary;
        $this->assertSame(1, $s['unpriced_items']);
    }

    public function test_a_free_lot_with_a_price_does_not_divide_by_zero(): void
    {
        $user = User::factory()->create();
        $this->seedItem(5000, 'Gift');
        $this->seedStats([5000 => ['floor' => 10_000]]);
        DB::table('trades')->insert(['user_id' => $user->id, 'item_id' => 5000, 'traded_at' => now()->subDay(), 'qty' => 10,
            'cost_copper' => 0, 'side' => 'buy', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $row = $this->rows($user)[5000];

        $this->assertNull($row->floorVsBreakEven);
        $this->assertSame('profit', $row->status);
    }

    public function test_floor_totals_cover_priced_items_only_and_net_of_the_cut(): void
    {
        $user = $this->fixtureUser(prices: true);

        $portfolio = app(PositionsService::class)->forUser($user);
        $rows = $this->rows($user);

        // 1000 x 330,000 x 0.95
        $this->assertSame(313_500_000, $rows[self::SHIMMERSIREN]->valueAtFloor);
        $this->assertSame(313_500_000 - 301_000_000, $rows[self::SHIMMERSIREN]->unrealized);

        $priced = array_filter($rows, fn (PositionRow $p) => $p->valueAtFloor !== null);
        $this->assertSame(array_sum(array_map(fn ($p) => $p->valueAtFloor, $priced)), $portfolio->summary['stock_at_floor']);
        $this->assertSame(array_sum(array_map(fn ($p) => $p->unrealized, $priced)), $portfolio->summary['unrealized']);
    }

    public function test_target_sell_uses_the_users_margin_and_break_even_the_ah_cut(): void
    {
        $user = $this->fixtureUser(attributes: ['target_margin_pct' => 15]);

        $p = $this->rows($user)[self::SHIMMERSIREN];
        // 1000 held at 301,000 copper each
        $this->assertEqualsWithDelta(301_000 / 0.95, $p->breakEven, 0.5);
        $this->assertEqualsWithDelta(301_000 * 1.15 / 0.95, $p->targetSell, 0.5);

        $user->update(['target_margin_pct' => 30]);
        $this->assertEqualsWithDelta(301_000 * 1.30 / 0.95, $this->rows($user->fresh())[self::SHIMMERSIREN]->targetSell, 0.5);
    }

    public function test_days_to_sell_is_held_over_sold_per_day_times_market_share(): void
    {
        $user = $this->fixtureUser(prices: true, attributes: ['market_share_pct' => 5]);

        // 1000 held / (900 per day x 5%)
        $this->assertEqualsWithDelta(1000 / (900 * 0.05), $this->rows($user)[self::SHIMMERSIREN]->daysToSell, 0.001);

        $user->update(['market_share_pct' => 10]);
        $this->assertEqualsWithDelta(1000 / (900 * 0.10), $this->rows($user->fresh())[self::SHIMMERSIREN]->daysToSell, 0.001);
    }

    public function test_concentration_warns_above_the_threshold_only(): void
    {
        $user = $this->fixtureUser();

        $c = app(PositionsService::class)->forUser($user)->concentration;
        $this->assertSame('Shimmersiren', $c['name']);
        $this->assertEqualsWithDelta(64.7, $c['pct'], 0.1);

        config(['ledger.positions.concentration_pct' => 70]);
        $this->assertNull(app(PositionsService::class)->forUser($user)->concentration);
    }

    public function test_items_sharing_a_name_are_told_apart_by_id(): void
    {
        $user = User::factory()->create();
        $this->seedItem(1, 'Rank Twin');
        $this->seedItem(2, 'Rank Twin');
        $this->seedItem(3, 'Loner');

        $this->importTrades($user, [[1, 10], [2, 20], [3, 30]]);

        $names = array_map(fn (PositionRow $p) => $p->name, $this->rows($user));

        // largest cost first: Loner (3,000), twin 2 (2,000), twin 1 (1,000)
        $this->assertSame(['Loner', 'Rank Twin #2', 'Rank Twin #1'], array_values($names));
    }

    public function test_unnamed_items_display_by_id(): void
    {
        $user = User::factory()->create();
        $this->importTrades($user, [[777, 5]]);

        $this->assertSame('Item #777', $this->rows($user)[777]->name);
    }

    public function test_recent_activity_merges_buys_and_sales_newest_first_and_skips_sales_before_the_first_buy(): void
    {
        $user = User::factory()->create();
        $this->seedItem(5, 'Thing');
        $this->importTrades($user, [[5, 100]], '2026-10-20T12:00:00Z');

        $this->seedSale($user, 5, 1, 100, '2026-10-20 11:00:00');   // before
        $this->seedSale($user, 5, 2, 100, '2026-10-20 13:00:00');
        $this->seedSale($user, 5, 3, 100, '2026-10-21 09:00:00');
        $this->seedSale($user, 5, 9, 100, '2026-10-22 09:00:00', source: 'Vendor');

        $recent = app(PositionsService::class)->forUser($user)->recent;

        $this->assertSame(['sale', 'sale', 'buy'], array_column($recent, 'type'));
        $this->assertSame([3, 2, 100], array_column($recent, 'qty'));
        // sales: qty x unit (after the cut); the buy: the lot total
        $this->assertSame([300, 200, 10_000], array_column($recent, 'total'));
    }

    public function test_one_users_ledger_never_includes_anothers_trades_or_sales(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->loadLedgerFixture($alice);

        $this->assertTrue(app(PositionsService::class)->forUser($bob)->isEmpty());

        $this->importTrades($bob, [[self::PORK, 1]]);
        $pork = $this->rows($bob)[self::PORK];

        $this->assertSame(1, $pork->held, "Bob's Pork must not pick up Alice's sales or buys");
        $this->assertSame(0, $pork->soldQty);
    }

    public function test_prices_come_from_the_users_region(): void
    {
        $user = User::factory()->create();
        $this->importTrades($user, [[5, 10]]);
        $this->seedStats([5 => ['floor' => 123]], 'eu');

        $this->assertNull($this->rows($user)[5]->floor);

        $this->seedStats([5 => ['floor' => 456]], 'us');
        $this->assertSame(456, $this->rows($user)[5]->floor);
    }

    /**
     * @param  list<array{int, int}>  $lots  [item id, qty], each costing 100 copper per unit
     */
    private function importTrades(User $user, array $lots, string $at = '2026-10-12T10:51:00Z'): void
    {
        $csv = "time,item,qty,cost,note\n";

        foreach ($lots as [$id, $qty]) {
            $csv .= "{$at},i:{$id},{$qty},".($qty * 100).'c,'."\n";
        }

        app(TradesImportService::class)->import($user, $csv);
    }
}
