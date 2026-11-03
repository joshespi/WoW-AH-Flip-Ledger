<?php

namespace Tests\Feature;

use App\Livewire\CorrectHoldings;
use App\Livewire\Trades;
use App\Models\Trade;
use App\Models\User;
use App\Services\PositionsService;
use App\Services\TradesService;
use App\Support\Utc;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class CorrectHoldingsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;

    private const T = 1_791_823_860; // 2026-10-12 16:51 UTC

    /** 100 bought at 1,000 each, 40 sold at 2,000: 60 held, 40,000 realized. */
    private function user(): User
    {
        $this->seedItem(100, 'Sin\'dorei Swarmer');
        $user = User::factory()->create(['timezone' => 'UTC']);
        app(TradesService::class)->add($user, 100, 100, 100_000, self::T, null);
        DB::table('tsm_sales')->insert([
            'user_id' => $user->id, 'realm' => 'R', 'item_id' => 100, 'qty' => 40, 'unit_price_copper' => 2000,
            'sold_at' => Utc::sql(self::T + 100), 'source' => 'Auction', 'dedupe_key' => sha1('s'),
        ]);

        return $user;
    }

    private function position(User $user): object
    {
        return collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 100);
    }

    public function test_zeroing_a_position_removes_it_with_no_pnl(): void
    {
        $user = $this->user();

        app(TradesService::class)->correctHeld($user, 100, 0, 'adjust', 'never had them');

        $p = $this->position($user);
        $this->assertSame(0, $p->held);
        $this->assertSame(0, $p->costBasis);
        $this->assertSame(40_000, $p->realized);
        $this->assertDatabaseHas('trades', ['user_id' => $user->id, 'side' => 'adjust', 'qty' => 60, 'note' => 'never had them', 'source' => 'manual']);
    }

    public function test_a_write_off_books_the_removed_cost_as_a_loss(): void
    {
        $user = $this->user();

        app(TradesService::class)->correctHeld($user, 100, 20, 'writeoff');

        $p = $this->position($user);
        $this->assertSame(20, $p->held);
        $this->assertSame(20_000, $p->costBasis);
        $this->assertSame(0, $p->realized); // 40,000 profit on the sale, less 40 units at 1,000 written off
        $this->assertSame(20, app(PositionsService::class)->held($user, 100));
    }

    public function test_only_a_lower_whole_number_is_accepted(): void
    {
        $user = $this->user();
        $trades = app(TradesService::class);

        foreach ([60, 61] as $held) {
            try {
                $trades->correctHeld($user, 100, $held, 'adjust');
                $this->fail("held {$held} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('less than 60', $e->getMessage());
            }
        }

        try {
            $trades->correctHeld($user, 100, 0, 'buy');
            $this->fail('a buy side was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('what happened', $e->getMessage());
        }

        $this->expectExceptionMessage('holds none');
        $trades->correctHeld($user, 999, 0, 'adjust');
    }

    public function test_the_component_corrects_and_undoes(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 60])
            ->assertSet('held', '0')
            ->set('held', 'ten')->call('save')->assertHasErrors('correct')
            ->set('held', '10')->set('reason', 'writeoff')->call('save')
            ->assertHasNoErrors()->assertRedirect(route('items.show', 100).'#correct');

        $this->assertSame(10, $this->position($user)->held);
        $id = Trade::where('side', 'writeoff')->value('id');

        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 10])
            ->assertSee('Written off')->call('undo', $id)->assertRedirect();

        $this->assertSame(60, $this->position($user)->held);
    }

    public function test_less_tsm_stock_warns_about_uncollected_mail_but_is_not_the_default(): void
    {
        $user = $this->user();
        DB::table('inventory')->insert(['user_id' => $user->id, 'item_id' => 100, 'qty' => 25, 'taken_at' => Utc::sql(self::T + 200)]);

        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 60])
            ->assertSet('held', '0')->assertSee('TSM stock: 25')->assertSee('shows 35 fewer')->assertSee('count those sales twice');
    }

    public function test_tsm_stock_is_zero_when_a_snapshot_lacks_the_item_and_unknown_without_one(): void
    {
        $user = $this->user();
        $positions = app(PositionsService::class);

        $this->assertNull($positions->tsmStock($user, 100));
        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 60])
            ->assertDontSee('TSM stock')->assertDontSee('count those sales twice');

        DB::table('inventory')->insert(['user_id' => $user->id, 'item_id' => 555, 'qty' => 3, 'taken_at' => Utc::sql(self::T + 200)]);

        $this->assertSame(0, $positions->tsmStock($user, 100));
        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 60])
            ->assertSee('TSM stock: 0')->assertSee('shows 60 fewer');
    }

    public function test_no_warning_when_tsm_stock_covers_the_ledger(): void
    {
        $user = $this->user();
        DB::table('inventory')->insert(['user_id' => $user->id, 'item_id' => 100, 'qty' => 60, 'taken_at' => Utc::sql(self::T + 200)]);

        Livewire::actingAs($user)->test(CorrectHoldings::class, ['itemId' => 100, 'ledgerHeld' => 60])
            ->assertSet('held', '0')->assertSee('TSM stock: 60')->assertDontSee('count those sales twice');
    }

    public function test_another_users_correction_cannot_be_undone(): void
    {
        $user = $this->user();
        $other = User::factory()->create();
        app(TradesService::class)->add($other, 100, 5, 5000, self::T, null);
        $theirs = app(TradesService::class)->correctHeld($other, 100, 0, 'adjust');

        $this->expectException(ModelNotFoundException::class);
        app(TradesService::class)->deleteCorrection($user, $theirs->id);
    }

    public function test_a_buy_cannot_be_undone_as_a_correction(): void
    {
        $user = $this->user();

        $this->expectException(ModelNotFoundException::class);
        app(TradesService::class)->deleteCorrection($user, Trade::where('side', 'buy')->value('id'));
    }

    public function test_screens_link_to_and_show_corrections(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/positions')->assertSee(route('items.show', 100).'#correct', false);
        $this->get('/items/100')->assertSee('Correct holdings')->assertSee('Ledger: 60');

        app(TradesService::class)->correctHeld($user, 100, 0, 'writeoff');

        $this->get('/items/100')->assertSee('Written off')->assertSee('Your corrections');
        $this->get('/positions')->assertDontSee(route('items.show', 100).'#correct', false);

        // Listed on the trades page as a correction, with no edit.
        Livewire::actingAs($user)->test(Trades::class)->assertSee('Written off')->assertSee('Undo this correction?')
            ->call('edit', Trade::where('side', 'writeoff')->value('id'))->assertNotFound();
    }
}
