<?php

namespace Tests\Feature;

use App\Livewire\Trades;
use App\Models\Trade;
use App\Models\User;
use App\Services\ItemDetailService;
use App\Services\LedgerService;
use App\Services\PositionsService;
use App\Services\TradesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\SeedsMarketData;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class CraftTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;
    use SeedsUserRows;

    private const T = 1_791_823_860;

    /** 100 of item 100 held at 1,000 each. At T+10: 5 of 400 from 20 of item 100 and 10 vendor 300 at 50 each. Cost 20,500. */
    private function crafted(): User
    {
        foreach ([100 => 'Ore', 300 => 'Vial', 400 => 'Potion'] as $id => $name) {
            $this->seedItem($id, $name);
        }

        $user = User::factory()->create(['timezone' => 'UTC']);
        app(TradesService::class)->add($user, 100, 100, 100_000, self::T, null);
        app(TradesService::class)->addCraft($user, 400, 5, [
            ['item' => 100, 'qty' => 20, 'vendor' => null],
            ['item' => 300, 'qty' => 10, 'vendor' => 50],
        ], self::T + 10);

        return $user;
    }

    private function row(User $user, int $id): ?object
    {
        return collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', $id);
    }

    public function test_a_craft_moves_ingredient_cost_into_the_product_with_no_pnl(): void
    {
        $user = $this->crafted();

        $ore = $this->row($user, 100);
        $this->assertSame([80, 80_000, 0], [$ore->held, $ore->costBasis, $ore->realized]);

        $vial = $this->row($user, 300);
        $this->assertSame([0, 0, 0], [$vial->held, $vial->costBasis, $vial->realized]);

        $potion = $this->row($user, 400);
        $this->assertSame([5, 20_500, 0], [$potion->held, $potion->costBasis, $potion->realized]);
        $this->assertSame([], app(PositionsService::class)->realizedEvents($user));
    }

    public function test_selling_the_product_books_profit_against_the_crafted_cost(): void
    {
        $user = $this->crafted();
        $this->seedSale($user, 400, 2, 5000, self::T + 20);
        $this->seedSale($user, 400, 1, 5000, self::T); // before the craft: ignored

        $potion = $this->row($user, 400);
        $this->assertSame([3, 12_300, 10_000 - 8_200], [$potion->held, $potion->costBasis, $potion->realized]);
    }

    public function test_item_pages_agree_with_the_ledger(): void
    {
        $user = $this->crafted();
        $this->seedSale($user, 400, 2, 5000, self::T + 20);
        $details = app(ItemDetailService::class);

        foreach ([100, 300, 400] as $id) {
            $d = $details->forUser($user, $id);
            $p = $this->row($user, $id);
            $this->assertSame([$p->held, $p->realized], [$d['position']['held'], $d['position']['realized']], "item $id");
        }

        $this->assertSame(['sale', 'craftin'], array_column($details->forUser($user, 400)['timeline'], 'kind'));
        $this->assertSame(3, app(PositionsService::class)->held($user, 400));
        $this->assertSame(80, app(PositionsService::class)->held($user, 100));
    }

    public function test_ledger_order_at_one_time_is_buy_then_use_then_product_then_sale(): void
    {
        $out = (new LedgerService)->buildFromSales(
            [['ts' => 10, 'item' => 1, 'qty' => 10, 'cost' => 1000]],
            [['item' => 2, 'ts' => 10, 'qty' => 2, 'unit_price' => 600]],
            null, [],
            [['ts' => 10, 'item' => 1, 'qty' => 10, 'craft' => 7, 'kind' => 'out'], ['ts' => 10, 'item' => 2, 'qty' => 2, 'craft' => 7, 'kind' => 'in']],
        );

        $this->assertSame([0, 0], [$out[1]['inv'], $out[1]['cost']]);
        $this->assertSame([2, 1200 - 1000], [$out[2]['sq'], $out[2]['pnl']]);
    }

    public function test_it_refuses_ingredients_you_do_not_hold(): void
    {
        $user = $this->crafted();

        $this->expectException(InvalidArgumentException::class);
        app(TradesService::class)->addCraft($user, 400, 1, [['item' => 100, 'qty' => 81, 'vendor' => null]], self::T + 30);
    }

    public function test_a_failed_craft_writes_nothing(): void
    {
        $user = $this->crafted();
        $before = Trade::count();

        try {
            app(TradesService::class)->addCraft($user, 400, 1, [['item' => 300, 'qty' => 1, 'vendor' => 5], ['item' => 100, 'qty' => 999, 'vendor' => null]], self::T + 30);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($before, Trade::count());
    }

    public function test_deleting_a_craft_takes_every_row_and_nothing_else(): void
    {
        $user = $this->crafted();
        $other = User::factory()->create();
        $id = Trade::where('side', Trade::SIDE_CRAFTIN)->value('craft_id');

        // made, two used, one vendor buy; plus the ore buy outside the craft.
        $this->assertSame(4, Trade::where('craft_id', $id)->count());
        $this->assertSame(5, Trade::count());

        try {
            app(TradesService::class)->deleteCraft($other, $id);
            $this->fail('another user must not delete it');
        } catch (NotFoundHttpException) {
        }

        app(TradesService::class)->deleteCraft($user, $id);

        $this->assertSame(1, Trade::count());
        $this->assertSame(100, $this->row($user, 100)->held);
    }

    public function test_a_craft_buy_cannot_be_deleted_alone(): void
    {
        $user = $this->crafted();
        $buy = Trade::where('side', Trade::SIDE_BUY)->where('source', Trade::SOURCE_CRAFT)->sole();

        $this->expectException(InvalidArgumentException::class);
        app(TradesService::class)->delete($user, $buy->id);
    }

    public function test_a_craft_buy_cannot_be_edited_alone(): void
    {
        $user = $this->crafted();
        $buy = Trade::where('side', Trade::SIDE_BUY)->where('source', Trade::SOURCE_CRAFT)->sole();

        $this->expectException(InvalidArgumentException::class);
        app(TradesService::class)->update($user, $buy->id, 300, 10, 1, self::T + 10, null);
    }

    public function test_the_form_records_a_craft_with_a_vendor_ingredient(): void
    {
        foreach ([100 => 'Ore', 300 => 'Vial', 400 => 'Potion'] as $id => $name) {
            $this->seedItem($id, $name);
        }

        $user = User::factory()->create(['timezone' => 'UTC']);
        app(TradesService::class)->add($user, 100, 10, 10_000, time() - 60, null);

        Livewire::actingAs($user)->test(Trades::class)
            ->set('product', 'i:400')->set('made', '2')
            ->set('ingredients.0.item', 'i:100')->set('ingredients.0.qty', '4')
            ->call('addCraftRow')
            ->set('ingredients.1.item', 'i:300')->set('ingredients.1.qty', '2')->set('ingredients.1.vendor', true)->set('ingredients.1.price', '0.5')
            ->call('saveCraft')->assertHasNoErrors()->assertSet('product', '');

        $potion = $this->row($user, 400);
        $this->assertSame([2, 4_000 + 10_000], [$potion->held, $potion->costBasis]);
    }

    public function test_craft_rows_stay_out_of_trades_csv(): void
    {
        $user = $this->crafted();

        $csv = $this->actingAs($user)->get('/trades/export')->streamedContent();

        $this->assertSame(2, count(array_filter(explode("\n", trim($csv))))); // header and the one plain buy
    }
}
