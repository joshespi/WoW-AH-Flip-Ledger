<?php

namespace Tests\Feature;

use App\Livewire\Recipes;
use App\Livewire\Trades;
use App\Models\Recipe;
use App\Models\Trade;
use App\Models\User;
use App\Services\ItemDetailService;
use App\Services\PositionsService;
use App\Services\RecipesService;
use App\Services\TradesService;
use App\Support\Game;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\SeedsMarketData;
use Tests\Support\SeedsUserRows;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMarketData;
    use SeedsUserRows;

    private const T = 1_791_823_860;

    /**
     * Recipe for item 400 bought for 7,500 at T. 5 of 400 bought at T+5 for 2,500 (no recipe), 5 more
     * crafted at T+10 from 5 ore (cost 5,000) with the recipe. 7 sold at T+20 for 2,000 each.
     * Average cost 750, so 1,250 profit a unit: 8,750. FIFO: the first 5 sold are bought ones, 2 are crafted.
     *
     * @return array{0: User, 1: Recipe}
     */
    private function setUpRecipe(): array
    {
        foreach ([100 => 'Ore', 400 => 'Potion'] as $id => $name) {
            $this->seedItem($id, $name);
        }

        $user = User::factory()->create(['timezone' => 'UTC']);
        $trades = app(TradesService::class);
        $recipe = app(RecipesService::class)->add($user, 'Recipe: Potion', 400, 7_500, self::T);

        $trades->add($user, 100, 100, 100_000, self::T + 1, null);
        $trades->add($user, 400, 5, 2_500, self::T + 5, null);
        $trades->addCraft($user, 400, 5, [['item' => 100, 'qty' => 5, 'vendor' => null]], self::T + 10, null, recipeId: $recipe->id);
        $this->seedSale($user, 400, 7, 2_000, self::T + 20);

        return [$user, $recipe];
    }

    public function test_repeating_a_craft_keeps_its_recipe_and_note(): void
    {
        [$user, $recipe] = $this->setUpRecipe();
        $source = Trade::where('side', Trade::SIDE_CRAFTIN)->sole();
        $source->update(['note' => 'batch A']);

        $made = app(TradesService::class)->repeatCraft($user, $source->id, 5, self::T + 30);

        $this->assertSame([$recipe->id, 'batch A'], [(int) $made->fresh()->recipe_id, $made->note]);
    }

    public function test_the_recipe_cost_is_a_realized_loss_on_its_product(): void
    {
        [$user] = $this->setUpRecipe();

        $potion = collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 400);
        $this->assertSame(8_750 - 7_500, $potion->realized);
        $this->assertSame(3, $potion->held);
        $this->assertContains(-7_500, array_column(app(PositionsService::class)->realizedEvents($user), 1));
    }

    public function test_roi_credits_only_units_crafted_with_the_recipe_oldest_stock_first(): void
    {
        [$user, $recipe] = $this->setUpRecipe();

        $row = app(RecipesService::class)->forUser($user)[0];

        $this->assertSame($recipe->id, $row['id']);
        $this->assertSame([1, 5, 2], [$row['crafts'], $row['made'], $row['sold']]);
        $this->assertSame([2_500, 2_500 - 7_500, 5_000], [$row['profit'], $row['net'], $row['to_go']]);
        $this->assertEqualsWithDelta(-5_000 / 7_500, $row['roi'], 1e-9);
    }

    public function test_roi_is_null_until_a_unit_sells(): void
    {
        [$user] = $this->setUpRecipe();
        \DB::table('tsm_sales')->delete();

        $row = app(RecipesService::class)->forUser($user)[0];
        $this->assertSame([0, 0, null], [$row['sold'], $row['profit'], $row['roi']]);
    }

    public function test_the_item_page_agrees_with_the_ledger(): void
    {
        [$user] = $this->setUpRecipe();

        $d = app(ItemDetailService::class)->forUser($user, 400);
        $this->assertSame(1_250, $d['position']['realized']);
        $this->assertContains('recipe', array_column($d['timeline'], 'kind'));
    }

    public function test_a_craft_can_only_link_to_your_own_recipe_for_that_item(): void
    {
        [$user, $recipe] = $this->setUpRecipe();
        $other = User::factory()->create();
        $trades = app(TradesService::class);
        $this->seedItem(500, 'Other');

        foreach ([[$other, 400, $recipe->id], [$user, 500, $recipe->id]] as [$who, $product, $id]) {
            try {
                $trades->addCraft($who, $product, 1, [['item' => 100, 'qty' => 1, 'vendor' => 5]], self::T + 30, null, recipeId: $id);
                $this->fail('linked');
            } catch (InvalidArgumentException) {
                $this->assertSame(1, Trade::where('side', Trade::SIDE_CRAFTIN)->count());
            }
        }
    }

    public function test_deleting_a_recipe_unlinks_its_crafts_and_drops_the_loss(): void
    {
        [$user, $recipe] = $this->setUpRecipe();

        app(RecipesService::class)->delete($user, $recipe->id);

        $this->assertSame(1, Trade::where('side', Trade::SIDE_CRAFTIN)->whereNull('recipe_id')->count());
        $potion = collect(app(PositionsService::class)->forUser($user)->positions)->firstWhere('itemId', 400);
        $this->assertSame(8_750, $potion->realized);
    }

    public function test_another_users_recipe_is_not_found(): void
    {
        [, $recipe] = $this->setUpRecipe();
        $other = User::factory()->create();

        $this->expectException(ModelNotFoundException::class);
        app(RecipesService::class)->delete($other, $recipe->id);
    }

    public function test_bad_input_is_refused(): void
    {
        $user = User::factory()->create();

        foreach ([['', 400, 100], ['Name', 400, 0], ['Name', 0, 100]] as [$name, $item, $cost]) {
            try {
                app(RecipesService::class)->add($user, $name, $item, $cost, self::T);
                $this->fail('accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Recipe::count());
            }
        }
    }

    public function test_the_page_adds_a_recipe_and_the_craft_form_links_it(): void
    {
        $this->seedItem(100, 'Ore');
        $this->seedItem(400, 'Potion');
        $user = User::factory()->create(['timezone' => 'UTC']);
        app(TradesService::class)->add($user, 100, 10, 10_000, time() - 120, null);

        Livewire::actingAs($user)->test(Recipes::class)
            ->set('name', 'Recipe: Potion')->set('item', 'Potion')->set('cost', '75s')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(7_500, (int) Recipe::sole()->cost_copper);

        Livewire::actingAs($user)->test(Trades::class)
            ->set('product', 'Potion')->set('made', '2')
            ->set('ingredients', [['item' => 'Ore', 'qty' => '2', 'vendor' => false, 'price' => '']])
            ->call('saveCraft')->assertHasNoErrors();

        $this->assertSame(Recipe::sole()->id, (int) Trade::where('side', Trade::SIDE_CRAFTIN)->sole()->recipe_id);
    }

    public function test_a_backdated_craft_links_existing_sales_to_its_recipe_in_forever(): void
    {
        $game = Game::FOREVER;
        $user = User::factory()->create(['timezone' => 'UTC', 'enabled_games' => $game, 'game_view' => $game]);
        $recipes = app(RecipesService::class);
        $recipe = $recipes->add($user, 'Recipe: Savory Deviate Delight', 6657, 500, self::T, game: $game);
        DB::table('user_item_names')->insert(['user_id' => $user->id, 'game' => $game, 'item_id' => 6522, 'name' => 'Deviate Fish']);
        $trades = app(TradesService::class);
        $trades->add($user, 6522, 5, 350, self::T, null, game: $game);
        $this->seedSale($user, 6657, 5, 200, self::T + 120, $game);
        $this->seedSale($user, 6522, 5, 100, self::T + 120, $game);

        $this->actingAs($user)->get(route('recipes.index'))->assertOk()
            ->assertSee('No crafts recorded')->assertSee(route('trades.index', ['recipe' => $recipe->id]).'#record-craft');

        Livewire::actingAs($user)->withQueryParams(['recipe' => (string) $recipe->id])->test(Trades::class)
            ->assertSet('product', 'i:6657')->assertSet('craftRecipe', (string) $recipe->id)
            ->set('craftTime', $user->inputTime(self::T + 60))->set('made', '5')
            ->set('ingredients', [['item' => 'Deviate Fish', 'qty' => '5', 'vendor' => false, 'price' => '']])
            ->call('saveCraft')->assertHasNoErrors();

        $row = $recipes->forUser($user, $game)[0];
        $this->assertSame([1, 5, 5, 650, 150], [$row['crafts'], $row['made'], $row['sold'], $row['profit'], $row['net']]);
    }

    public function test_changing_the_prefilled_craft_product_clears_the_recipe(): void
    {
        $user = User::factory()->create();
        $recipe = app(RecipesService::class)->add($user, 'Potion Recipe', 400, 500, self::T);

        Livewire::actingAs($user)->withQueryParams(['recipe' => (string) $recipe->id])->test(Trades::class)
            ->set('product', '400')
            ->assertSet('craftRecipe', (string) $recipe->id)
            ->set('product', 'i:401')
            ->assertSet('craftRecipe', '');

        Livewire::actingAs($user)->withQueryParams(['recipe' => (string) $recipe->id])->test(Trades::class)
            ->call('pickCraftItem', 401)
            ->assertSet('product', 'i:401')
            ->assertSet('craftRecipe', '');
    }
}
