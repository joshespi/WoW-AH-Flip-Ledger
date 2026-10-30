<?php

namespace Tests\Feature;

use App\Livewire\Market;
use App\Livewire\Scan;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\ScanService;
use App\Services\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class MarketTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private function user(): User
    {
        $this->seedItem(100, 'Practically Pork');
        $this->seedItem(200, 'Arcane Wyrmfish');
        $this->seedItem(300, 'Void Leather');
        $this->seedItem(400, 'Young Item');

        // 100: cheap and falling. 200: normal. 300: rich. 400: not enough history.
        $this->seedStats([
            100 => ['floor' => 70, 'recent' => 85, 'typical' => 100, 'sold' => 500, 'days' => 20],
            200 => ['floor' => 100, 'recent' => 100, 'typical' => 100, 'sold' => 50, 'days' => 20],
            300 => ['floor' => 150, 'recent' => 140, 'typical' => 100, 'sold' => 5, 'days' => 20],
            400 => ['floor' => 10, 'recent' => 20, 'typical' => 100, 'sold' => 900, 'days' => 3],
        ]);

        return User::factory()->create();
    }

    public function test_pages_require_login(): void
    {
        $this->get('/market')->assertRedirect('/login');
        $this->get('/scan')->assertRedirect('/login');
        $this->actingAs($this->user())->get('/market')->assertOk();
        $this->get('/scan')->assertOk();
    }

    public function test_add_edit_move_and_remove(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Market::class)
            ->set('item', 'practically pork')->set('group', '  Fish   food ')->call('add')->assertHasNoErrors()
            ->set('item', 'i:200')->call('add')
            ->set('item', 'i:200')->call('add')->assertHasErrors('add')
            ->set('item', 'nonsense')->call('add')->assertHasErrors('add');

        $w = WatchlistItem::where('item_id', 100)->sole();
        $this->assertSame('Fish food', $w->group_name);
        $this->assertSame(2, WatchlistItem::count());

        Livewire::actingAs($user)->test(Market::class)
            ->call('edit', $w->id)->set('buy', '12g 50s')->set('sell', '20')->set('notes', 'n')->set('editGroup', '')
            ->call('save')->assertHasNoErrors()->assertSet('editing', null);

        $w->refresh();
        $this->assertSame([12_5000, 20_0000, 'n', null], [$w->target_buy_copper, $w->target_sell_copper, $w->notes, $w->group_name]);

        Livewire::actingAs($user)->test(Market::class)
            ->call('edit', $w->id)->set('buy', 'lots')->call('save')->assertHasErrors('edit')
            ->call('remove', $w->id);

        $this->assertSame(1, WatchlistItem::count());
    }

    public function test_move_swaps_within_a_group(): void
    {
        $user = $this->user();
        $svc = app(WatchlistService::class);
        $a = $svc->add($user, 100, 'g');
        $b = $svc->add($user, 200, 'g');
        $c = $svc->add($user, 300, 'g');
        $other = $svc->add($user, 400);

        $svc->move($user, $c->id, -1);
        $this->assertSame([100, 300, 200, 400], array_column($svc->rows($user), 'item_id'));

        $svc->move($user, $a->id, -1);   // already first: no-op
        $svc->move($user, $other->id, 1); // alone in its group: no-op
        $this->assertSame([100, 300, 200, 400], array_column($svc->rows($user), 'item_id'));
        $this->assertNotNull($b);
    }

    public function test_rows_highlight_targets_and_gate_derived_numbers(): void
    {
        $user = $this->user();
        $svc = app(WatchlistService::class);

        foreach ([100, 200, 300, 400] as $id) {
            $svc->add($user, $id);
        }

        $svc->update($user, WatchlistItem::where('item_id', 100)->value('id'), '70c', '', null, null);   // floor 70 <= 70
        $svc->update($user, WatchlistItem::where('item_id', 300)->value('id'), '', '1c', null, null);
        $rows = collect($svc->rows($user))->keyBy('item_id');

        $this->assertTrue($rows[100]['at_buy']);
        $this->assertFalse($rows[100]['at_sell']);
        $this->assertTrue($rows[100]['knife']);
        $this->assertTrue($rows[300]['at_sell']);
        $this->assertFalse($rows[200]['knife']);

        $this->assertSame(10, $rows[400]['floor']);
        $this->assertFalse($rows[400]['ready']);
        $this->assertNull($rows[400]['recent']);
        $this->assertNull($rows[400]['sold']);
        $this->assertFalse($rows[400]['knife']);
    }

    public function test_watchlists_are_private(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $theirs = app(WatchlistService::class)->add($other, 100);

        Livewire::actingAs($user)->test(Market::class)->assertDontSee('Practically Pork')
            ->call('edit', $theirs->id)->assertSet('editing', null)
            ->call('move', $theirs->id, 1)->assertNotFound();

        Livewire::actingAs($user)->test(Market::class)->call('remove', $theirs->id)->assertNotFound();
        $this->assertSame(1, WatchlistItem::count());
    }

    public function test_scan_filters_orders_and_skips_young_items(): void
    {
        $user = $this->user();
        $scan = app(ScanService::class);
        $ids = fn (array $rows) => array_column($rows, 'item_id');

        $this->assertSame([100, 200, 300], $ids($scan->scan($user)));          // cheapest first, 400 excluded
        $this->assertSame([100], $ids($scan->scan($user, maxRatio: 0.75)));
        $this->assertSame([100, 200], $ids($scan->scan($user, minSoldPerDay: 10)));
        $this->assertSame([200, 300], $ids($scan->scan($user, minItemId: 150)));
        $this->assertSame([100], $ids($scan->scan($user, top: 1)));
        $this->assertTrue($scan->scan($user, top: 1)[0]['knife']);
    }

    public function test_scan_page_adds_to_the_watchlist(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Scan::class)
            ->assertSee('Practically Pork')->assertDontSee('Young Item')
            ->set('minDiscount', '25')->assertDontSee('Arcane Wyrmfish')
            ->call('add', 100)->assertHasNoErrors()->assertSee('Watching')
            ->call('add', 100)->assertHasErrors('add');

        $this->assertSame(1, WatchlistItem::where('user_id', $user->id)->count());
    }
}
