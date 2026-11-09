<?php

namespace Tests\Feature;

use App\Livewire\Reconcile;
use App\Livewire\Trades;
use App\Models\Trade;
use App\Models\User;
use App\Services\PositionsService;
use App\Services\ReconcileService;
use App\Services\TradesService;
use App\Support\Money;
use App\Support\Utc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private const T = 1_791_823_860; // 2026-10-12 16:51 UTC

    private function user(): User
    {
        $this->seedItem(100, 'Practically Pork');

        return User::factory()->create(['timezone' => 'UTC']);
    }

    private function candidate(User $user, string $ref, int $qty, int $cost = 5000, int $at = self::T, int $item = 100): int
    {
        return DB::table('tsm_buy_candidates')->insertGetId([
            'user_id' => $user->id, 'tsm_ref' => sha1($ref), 'realm' => 'Realm', 'item_id' => $item,
            'qty' => $qty, 'cost_copper' => $cost, 'at' => Utc::sql($at), 'status' => 'pending',
        ]);
    }

    private function buy(User $user, int $qty, int $at): void
    {
        app(TradesService::class)->add($user, 100, $qty, 1000, $at, null);
    }

    public function test_matching_buys_hide_a_candidate_and_doubles_are_flagged(): void
    {
        $user = $this->user();
        $this->buy($user, 100, self::T + 599);       // same qty, inside the window: matches
        $this->candidate($user, 'matched', 100);
        $this->candidate($user, 'outside', 100, 5000, self::T + 5000);  // trade is too far away
        $this->candidate($user, 'double', 200);       // twice a nearby trade
        $this->candidate($user, 'other', 7);          // nearby trade, unrelated qty

        $rows = app(ReconcileService::class)->pending($user);

        // Newest first; ties by id, descending.
        $this->assertSame([100, 7, 200], array_column($rows, 'qty'));
        $this->assertSame([false, false, true], array_column($rows, 'double'));
    }

    public function test_pages_past_matched_candidates_and_stops_at_the_limit(): void
    {
        $user = $this->user();

        // An hour apart (outside each other's window), newest first. The 550 newest each have a
        // matching buy, so the first page of 500 shows nothing; 400 unmatched follow.
        for ($i = 0; $i < 950; $i++) {
            $at = self::T - $i * 3600;
            $this->candidate($user, "c{$i}", $i < 550 ? 1 : 5, at: $at);

            if ($i < 550) {
                $this->buy($user, 1, $at);
            }
        }

        $rows = app(ReconcileService::class)->pending($user);

        $this->assertCount(ReconcileService::LIMIT, $rows);
        $this->assertSame(self::T - 550 * 3600, $rows[0]['at']);
        $this->assertSame([5], array_values(array_unique(array_column($rows, 'qty'))));
    }

    public function test_a_csv_buy_covers_candidates_at_a_similar_price_whatever_the_quantity(): void
    {
        $user = $this->user();
        DB::table('trades')->insert([
            'user_id' => $user->id, 'item_id' => 100, 'traded_at' => Utc::sql(self::T), 'qty' => 459, 'cost_copper' => 459 * 4500,
            'side' => 'buy', 'source' => 'csv', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->candidate($user, 'same-price', 906, 906 * 4600);
        $this->candidate($user, 'dearer', 906, 906 * 6000);
        $this->candidate($user, 'later', 906, 906 * 4500, self::T + 601);

        $rows = app(ReconcileService::class)->pending($user);

        $this->assertSame([906, 906], array_column($rows, 'qty'));
        $this->assertSame([self::T + 601, self::T], array_column($rows, 'at'));
    }

    public function test_window_is_ten_minutes_each_way(): void
    {
        $user = $this->user();
        $this->buy($user, 5, self::T + 601);
        $this->candidate($user, 'a', 5);

        $this->assertCount(1, app(ReconcileService::class)->pending($user));

        $this->buy($user, 5, self::T - 600);

        $this->assertCount(0, app(ReconcileService::class)->pending($user));
    }

    public function test_accept_creates_a_tsm_buy_once(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 50, 12_345);

        Livewire::actingAs($user)->test(Reconcile::class)->call('accept', $id)->assertHasNoErrors()->assertSee('Buy added.');

        $t = Trade::sole();
        $this->assertSame([100, 50, 12_345, 'tsm', sha1('a')], [$t->item_id, $t->qty, $t->cost_copper, $t->source, $t->tsm_ref]);
        $this->assertSame('accepted', DB::table('tsm_buy_candidates')->value('status'));

        Livewire::actingAs($user)->test(Reconcile::class)->call('accept', $id)->assertHasErrors('row'.$id);
        $this->assertSame(1, Trade::count());
    }

    public function test_edit_then_accept_uses_the_corrected_values(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 200, 20_000);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->call('edit', $id)->assertSet('qty', '200')->assertSet('time', '2026-10-12T16:51')
            ->set('qty', '100')->set('cost', '1g')->set('time', '2026-10-12T16:00')
            ->call('saveEdited')->assertHasNoErrors()->assertSet('editing', null);

        $t = Trade::sole();
        $this->assertSame([100, 10_000, '2026-10-12 16:00:00'], [$t->qty, $t->cost_copper, $t->traded_at->format('Y-m-d H:i:s')]);
    }

    public function test_corrections_do_not_count_as_recorded_buys(): void
    {
        $user = $this->user();
        $this->buy($user, 10, self::T - 5000);
        app(TradesService::class)->adjust($user, 100, 50, self::T);   // same qty and time, but not a buy
        $this->candidate($user, 'same', 50);
        $this->candidate($user, 'twice', 100);

        $rows = app(ReconcileService::class)->pending($user);

        $this->assertSame([false, false], array_column($rows, 'double'));
        $this->assertCount(2, $rows);
    }

    public function test_vendored_adds_the_buy_and_books_the_vendor_proceeds(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 50, 5000);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->call('edit', $id, true)->assertSet('vendorEach', '')
            ->set('vendorEach', '2s')->call('saveEdited')->assertHasNoErrors()->assertSet('editing', null)
            ->assertSee('Buy added and vendored.')->assertSee('Vendored so far');

        $v = Trade::where('side', 'vendor')->sole();
        $this->assertSame([100, 50, 10_000, '2026-10-12 16:51:00'], [$v->item_id, $v->qty, $v->cost_copper, $v->traded_at->format('Y-m-d H:i:s')]);
        $this->assertSame('accepted', DB::table('tsm_buy_candidates')->value('status'));

        $p = app(PositionsService::class)->forUser($user);
        $this->assertSame(5000, $p->summary['realized']);
        $this->assertSame(0, $p->positions[0]->held);
        $this->assertSame(['units' => 50, 'proceeds' => 10_000], app(ReconcileService::class)->vendoredTotals($user));

        // The next vendoring of this item starts from the same price.
        $next = $this->candidate($user, 'b', 10, 900, self::T + 3600);
        Livewire::actingAs($user)->test(Reconcile::class)->call('edit', $next, true)->assertSet('vendorEach', Money::fmt(200));
    }

    public function test_vendored_selected_uses_each_items_last_price_and_skips_unknown_ones(): void
    {
        $user = $this->user();
        $this->seedItem(200, 'Other');
        $first = $this->candidate($user, 'a', 10, 1000);
        Livewire::actingAs($user)->test(Reconcile::class)->call('edit', $first, true)->set('vendorEach', '2s')->call('saveEdited');

        $known = $this->candidate($user, 'b', 20, 1000, self::T + 3600);
        $unknown = $this->candidate($user, 'c', 5, 500, self::T + 7200, 200);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->set('selected', [$known, $unknown])->call('vendorSelected')->assertHasNoErrors()
            ->assertSee('1 buy added and vendored.')->assertSee('1 skipped')->assertSet('selected', []);

        $this->assertSame(4000, Trade::where('side', 'vendor')->orderByDesc('id')->value('cost_copper'));
        $this->assertSame(2, Trade::where('side', 'vendor')->count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->where('id', $unknown)->value('status'));
    }

    public function test_vendoring_without_a_price_changes_nothing(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 50);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->call('edit', $id, true)->call('saveEdited')->assertHasErrors('row'.$id);

        $this->assertSame(0, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
    }

    public function test_a_vendor_total_past_the_cost_limit_is_a_form_error(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 10_000_000);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->call('edit', $id, true)->set('vendorEach', '999999999999g')->call('saveEdited')->assertHasErrors('row'.$id);

        $this->assertSame(0, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
    }

    public function test_deleting_a_vendored_buy_deletes_its_vendor_sale_only(): void
    {
        $user = $this->user();
        $reconcile = app(ReconcileService::class);
        $first = $reconcile->acceptVendored($user, $this->candidate($user, 'a', 10), 200);
        $reconcile->acceptVendored($user, $this->candidate($user, 'b', 10), 200);

        $buy = Trade::where('side', 'buy')->where('tsm_ref', $first->tsm_ref)->sole();
        app(TradesService::class)->delete($user, $buy->id);

        $this->assertSame(1, Trade::where('side', 'buy')->count());
        $this->assertSame([sha1('b')], Trade::where('side', 'vendor')->pluck('tsm_ref')->all());
    }

    public function test_editing_a_vendored_buy_moves_its_vendor_sale_with_it(): void
    {
        $user = $this->user();
        $this->seedItem(200, 'Other');
        $buy = app(ReconcileService::class)->acceptVendored($user, $this->candidate($user, 'a', 10), 200);
        $buy = Trade::where('side', 'buy')->where('tsm_ref', $buy->tsm_ref)->sole();
        $this->buy($user, 30, self::T - 3600); // other stock the vendor sale must not eat

        app(TradesService::class)->update($user, $buy->id, 200, 4, 2000, self::T + 60, null);

        $v = Trade::where('side', 'vendor')->sole();
        $this->assertSame([200, 4, 800, self::T + 60], [$v->item_id, $v->qty, $v->cost_copper, $v->traded_at->getTimestamp()]);
        $this->assertSame(30, app(PositionsService::class)->held($user, 100));
        $this->assertSame(0, app(PositionsService::class)->held($user, 200));
    }

    public function test_editing_a_vendored_buy_past_the_cost_limit_changes_nothing(): void
    {
        $user = $this->user();
        app(ReconcileService::class)->acceptVendored($user, $this->candidate($user, 'a', 10), 1_000_000_000);
        $buy = Trade::where('side', 'buy')->sole();

        try {
            app(TradesService::class)->update($user, $buy->id, 100, 10_000_000, 5000, self::T, null);
            $this->fail('Expected the vendor total to be refused.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([10, 10], Trade::orderBy('id')->pluck('qty')->all());
    }

    public function test_the_delete_warning_mentions_a_vendor_sale_only_when_there_is_one(): void
    {
        $user = $this->user();
        $reconcile = app(ReconcileService::class);
        $reconcile->acceptVendored($user, $this->candidate($user, 'a', 10), 200);

        $warning = 'A vendor sale booked with it is deleted too.';
        Livewire::actingAs($user)->test(Trades::class)->assertSee($warning);

        Trade::where('side', 'vendor')->delete();
        Livewire::actingAs($user)->test(Trades::class)->assertDontSee($warning);
    }

    public function test_a_vendor_sale_can_not_be_added_through_correct_held(): void
    {
        $user = $this->user();
        $this->buy($user, 10, self::T);

        $this->expectException(\InvalidArgumentException::class);
        app(TradesService::class)->correctHeld($user, 100, 0, 'vendor');
    }

    public function test_bad_edit_keeps_the_candidate_pending(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 200);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->call('edit', $id)->set('qty', '0')->call('saveEdited')->assertHasErrors('row'.$id);

        $this->assertSame(0, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
    }

    public function test_ignored_stays_ignored_after_a_reupload(): void
    {
        $user = $this->user();
        $id = $this->candidate($user, 'a', 50);

        Livewire::actingAs($user)->test(Reconcile::class)->call('ignore', $id);

        // Ingest does insertOrIgnore on (user_id, tsm_ref): the row, and its status, survive.
        DB::table('tsm_buy_candidates')->insertOrIgnore([
            'user_id' => $user->id, 'tsm_ref' => sha1('a'), 'realm' => 'Realm', 'item_id' => 100,
            'qty' => 50, 'cost_copper' => 5000, 'at' => Utc::sql(self::T), 'status' => 'pending',
        ]);

        $this->assertSame([], app(ReconcileService::class)->pending($user));
    }

    public function test_other_users_candidates_are_invisible_and_untouchable(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $theirs = $this->candidate($other, 'theirs', 50);

        Livewire::actingAs($user)->test(Reconcile::class)->assertSee('Nothing to reconcile.')
            ->call('accept', $theirs)->assertHasErrors('row'.$theirs)
            ->call('ignore', $theirs);

        $this->assertSame(0, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
    }

    public function test_accept_selected_adds_buys_and_skips_what_cannot_be_added(): void
    {
        $user = $this->user();
        $a = $this->candidate($user, 'a', 10);
        $b = $this->candidate($user, 'b', 20);
        $bad = $this->candidate($user, 'bad', 0);
        $left = $this->candidate($user, 'left', 30);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->set('selected', [(string) $a, (string) $b, (string) $bad])
            ->call('acceptSelected')->assertHasNoErrors()
            ->assertSee('2 buys added.')->assertSee('1 skipped')
            ->assertSet('selected', []);

        $this->assertSame([10, 20], Trade::orderBy('qty')->pluck('qty')->all());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->where('id', $left)->value('status'));
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->where('id', $bad)->value('status'));
    }

    public function test_ignore_selected_and_select_all_except_doubles(): void
    {
        $user = $this->user();
        $this->buy($user, 100, self::T);
        $double = $this->candidate($user, 'double', 200);
        $a = $this->candidate($user, 'a', 7);
        $b = $this->candidate($user, 'b', 8);

        $c = Livewire::actingAs($user)->test(Reconcile::class)->call('selectAll', true);
        $this->assertEqualsCanonicalizing([$a, $b], $c->get('selected'));

        $c->call('selectAll')->assertCount('selected', 3);
        $c->set('selected', [$a, $b])->call('ignoreSelected')->assertSee('2 suggestions ignored.');

        $this->assertSame(['pending', 'ignored', 'ignored'], DB::table('tsm_buy_candidates')->orderBy('id')->pluck('status')->all());
        $this->assertSame($double, DB::table('tsm_buy_candidates')->where('status', 'pending')->value('id'));
    }

    public function test_bulk_actions_cannot_touch_other_users_candidates(): void
    {
        $user = $this->user();
        $theirs = $this->candidate(User::factory()->create(), 'theirs', 50);

        Livewire::actingAs($user)->test(Reconcile::class)
            ->set('selected', [$theirs])->call('acceptSelected')->call('ignoreSelected');

        $this->assertSame(0, Trade::count());
        $this->assertSame('pending', DB::table('tsm_buy_candidates')->value('status'));
    }

    public function test_page_requires_login(): void
    {
        $this->get('/reconcile')->assertRedirect('/login');
        $this->actingAs($this->user())->get('/reconcile')->assertOk()->assertSee('Nothing to reconcile.');
    }
}
