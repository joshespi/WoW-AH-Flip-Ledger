<?php

namespace Tests\Feature;

use App\Livewire\Reconcile;
use App\Models\Trade;
use App\Models\User;
use App\Services\ReconcileService;
use App\Services\TradesService;
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
