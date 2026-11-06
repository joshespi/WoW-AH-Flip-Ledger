<?php

namespace Tests\Feature;

use App\Livewire\Trades;
use App\Models\Trade;
use App\Models\User;
use App\Services\TradesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class TradesPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private function user(): User
    {
        $this->seedItem(100, 'Practically Pork');
        $this->seedItem(200, 'Void-Tempered Leather');
        $this->seedItem(201, 'Void-Tempered Leather');

        return User::factory()->create(['timezone' => 'UTC']);
    }

    public function test_page_requires_login_and_renders(): void
    {
        $this->get('/trades')->assertRedirect('/login');
        $this->actingAs($this->user())->get('/trades')->assertOk()->assertSee('Add a buy');
    }

    public function test_adds_a_buy_and_shows_the_per_unit_price(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(Trades::class)
            ->set('item', 'practically pork')->set('qty', '10')->set('cost', '2,730g 82s')
            ->assertSet('unitCopper', 2_730_820)
            ->set('time', '2026-10-12T10:51')->set('note', 'test')
            ->call('save')->assertHasNoErrors()->assertSet('item', '');

        $t = Trade::where('user_id', $user->id)->sole();
        $this->assertSame([100, 10, 27_308_200, 'manual', 'test'], [$t->item_id, $t->qty, $t->cost_copper, $t->source, $t->note]);
        $this->assertSame('2026-10-12 10:51:00', $t->traded_at->format('Y-m-d H:i:s'));
    }

    public function test_times_are_read_in_the_users_timezone(): void
    {
        $user = $this->user();
        $user->update(['timezone' => 'America/Denver']);

        Livewire::actingAs($user)->test(Trades::class)
            ->set('item', 'i:100')->set('qty', '1')->set('cost', '1')->set('time', '2026-10-12T10:51')->call('save');

        $this->assertSame('2026-10-12 16:51:00', Trade::sole()->traded_at->format('Y-m-d H:i:s'));
    }

    public function test_bad_input_is_reported_and_nothing_is_saved(): void
    {
        $user = $this->user();

        foreach ([
            ['nonsense', '1', '1'],
            ['Void-Tempered Leather', '1', '1'],
            ['i:100', '0', '1'],
            ['i:100', '1.5', '1'],
            ['i:100', '1', 'lots'],
            ['i:100', '1', '-5'],
        ] as [$item, $qty, $cost]) {
            Livewire::actingAs($user)->test(Trades::class)
                ->set('item', $item)->set('qty', $qty)->set('cost', $cost)->call('save')->assertHasErrors('form');
        }

        Livewire::actingAs($user)->test(Trades::class)
            ->set('item', 'i:100')->set('qty', '1')->set('cost', '1')->set('time', 'now')->call('save')->assertHasErrors('form');

        $this->assertSame(0, Trade::count());
    }

    public function test_edits_and_deletes_a_buy(): void
    {
        $user = $this->user();
        $trade = app(TradesService::class)->add($user, 100, 5, 5000, time() - 60, null);

        Livewire::actingAs($user)->test(Trades::class)
            ->call('edit', $trade->id)->assertSet('qty', '5')->assertSet('cost', '50s 00c')
            ->set('qty', '7')->set('cost', '1g 5c')->call('save')->assertHasNoErrors()->assertSet('editing', null)
            ->call('delete', $trade->id);

        $this->assertSame(0, Trade::count());
    }

    public function test_cannot_touch_another_users_trade(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $theirs = app(TradesService::class)->add($other, 100, 5, 5000, time() - 60, null);

        Livewire::actingAs($user)->test(Trades::class)->call('edit', $theirs->id)->assertNotFound();
        Livewire::actingAs($user)->test(Trades::class)->call('delete', $theirs->id)->assertNotFound();

        $this->expectException(ModelNotFoundException::class);
        app(TradesService::class)->update($user, $theirs->id, 100, 9, 9, time() - 60, null);
    }

    public function test_list_shows_only_own_trades_and_filters(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $svc = app(TradesService::class);
        $svc->add($user, 100, 5, 5000, time() - 60, 'mine-pork');
        $svc->add($user, 200, 3, 3000, time() - 30, 'mine-leather');
        $svc->add($other, 100, 99, 9999, time() - 30, 'theirs');

        Livewire::actingAs($user)->test(Trades::class)
            ->assertSee('mine-pork')->assertSee('mine-leather')->assertDontSee('theirs')
            ->set('search', 'pork')->assertSee('mine-pork')->assertDontSee('mine-leather');
    }

    public function test_suggestions_match_known_names(): void
    {
        Livewire::actingAs($this->user())->test(Trades::class)
            ->set('item', 'prac')->assertSee('Practically Pork')
            ->call('pick', 100)->assertSet('item', 'i:100');
    }

    public function test_paste_adds_every_line_or_none(): void
    {
        $user = $this->user();
        $ok = "junk\nYou won an auction for [Practically Pork]x5351 for 2,730g 82s\nYou won an auction for [Void-Tempered Leather]x2 for 5g";

        Livewire::actingAs($user)->test(Trades::class)->set('pasted', $ok)->call('addPasted')->assertHasErrors('pasted');
        $this->assertSame(0, Trade::count());

        $ok = "junk\nYou won an auction for [Practically Pork]x5351 for 2,730g 82s\nYou won an auction for |Hitem:201::|h[Void-Tempered Leather]|hx2 for 5g";
        Livewire::actingAs($user)->test(Trades::class)->set('pasted', $ok)->call('addPasted')->assertHasNoErrors()->assertSet('pasted', '');

        $this->assertSame([100, 201], Trade::orderBy('id')->pluck('item_id')->all());
        $this->assertSame('paste', Trade::first()->source);
    }

    public function test_service_rejects_implausible_values(): void
    {
        $user = $this->user();
        $svc = app(TradesService::class);

        foreach ([[100, 0, 1], [100, 1, -1], [100, 1, 2_000_000_000_000], [0, 1, 1]] as [$id, $qty, $cost]) {
            try {
                $svc->add($user, $id, $qty, $cost, time(), null);
                $this->fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, DB::table('trades')->count());
    }

    public function test_export_is_scoped_round_trips_and_neutralizes_formulas(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        $svc = app(TradesService::class);
        $svc->add($user, 100, 5, 27_308_205, 1_791_823_860, '=SUM(A1)');
        $svc->add($other, 100, 1, 1, 1_791_823_860, 'theirs');

        $csv = $this->actingAs($user)->get('/trades/export')->assertOk()->streamedContent();

        $this->assertStringContainsString("2026-10-12T16:51:00+00:00,i:100,5,\"2,730g 82s 05c\",'=SUM(A1)", $csv);
        $this->assertStringNotContainsString('theirs', $csv);

        $this->get('/trades/export')->assertOk();
        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get('/trades/export')->assertRedirect('/login');
    }
}
