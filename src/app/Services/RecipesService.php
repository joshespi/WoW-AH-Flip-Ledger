<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\Trade;
use App\Models\User;
use App\Support\Bounds;
use App\Support\Game;
use App\Support\Utc;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recipes the user bought, and their ROI. A recipe's cost is booked as a loss on the item it
 * makes (LedgerService); crafts linked to it (trades.recipe_id on the craftin row) earn the
 * profit its ROI is measured against. Every write is scoped to the user.
 */
class RecipesService
{
    public const MAX_PER_USER = 500;

    public function __construct(private PositionsService $positions) {}

    /** @throws InvalidArgumentException */
    public function add(User $user, string $name, int $itemId, int $cost, int $ts, ?string $note = null, string $game = Game::RETAIL): Recipe
    {
        $attributes = $this->attributes($name, $itemId, $cost, $ts, $note);

        if (Recipe::ownedBy($user)->count() >= self::MAX_PER_USER) {
            throw new InvalidArgumentException('You can keep at most '.self::MAX_PER_USER.' recipes.');
        }

        return Recipe::create($attributes + ['user_id' => $user->id, 'game' => $game]);
    }

    /** @throws InvalidArgumentException */
    public function update(User $user, int $id, string $name, int $itemId, int $cost, int $ts, ?string $note): Recipe
    {
        $attributes = $this->attributes($name, $itemId, $cost, $ts, $note);

        return DB::transaction(function () use ($user, $id, $attributes, $itemId) {
            $recipe = Recipe::ownedBy($user)->findOrFail($id);

            // Crafts of another item can't stay linked.
            if ((int) $recipe->item_id !== $itemId) {
                Trade::ownedBy($user)->where('recipe_id', $id)->update(['recipe_id' => null]);
            }

            $recipe->update($attributes);

            return $recipe;
        });
    }

    /** Its crafts stay, unlinked; the cost leaves the ledger with the recipe. */
    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $recipe = Recipe::ownedBy($user)->findOrFail($id);

            Trade::ownedBy($user)->where('recipe_id', $id)->update(['recipe_id' => null]);
            $recipe->delete();
        });
    }

    /**
     * One row per recipe: what it cost, the crafts and units made with it, the units sold and the
     * profit on them (before the cost), then net and ROI. ROI is null until a unit has sold.
     *
     * @return list<array{id: int, name: string, item_id: int, item_name: ?string, bought_at: string, cost: int, crafts: int, made: int, sold: int, profit: int, net: int, roi: ?float, to_go: int}>
     */
    public function forUser(User $user, string $game = Game::RETAIL): array
    {
        $recipes = Recipe::ownedBy($user)->inGame($game)->orderByDesc('bought_at')->orderByDesc('id')->get();

        if ($recipes->isEmpty()) {
            return [];
        }

        $made = DB::table('trades')->ownedBy($user)->inGame($game)->whereNotNull('recipe_id')->where('side', Trade::SIDE_CRAFTIN)
            ->groupBy('recipe_id')->selectRaw('recipe_id, count(*) as crafts, sum(qty) as made')->get()->keyBy('recipe_id');
        $stats = $this->positions->recipeStats($user, $game);
        $names = DB::table('items')->whereIn('id', $recipes->pluck('item_id'))->pluck('name', 'id');

        return $recipes->map(function (Recipe $r) use ($made, $stats, $names): array {
            $cost = (int) $r->cost_copper;
            $profit = $stats[$r->id]['profit'] ?? 0;
            $sold = $stats[$r->id]['sold'] ?? 0;
            $net = $profit - $cost;

            return [
                'id' => $r->id,
                'name' => $r->name,
                'item_id' => (int) $r->item_id,
                'item_name' => $names[$r->item_id] ?? null,
                'bought_at' => $r->getRawOriginal('bought_at'),
                'cost' => $cost,
                'crafts' => (int) ($made[$r->id]->crafts ?? 0),
                'made' => (int) ($made[$r->id]->made ?? 0),
                'sold' => $sold,
                'profit' => $profit,
                'net' => $net,
                'roi' => $sold > 0 && $cost > 0 ? $net / $cost : null,
                'to_go' => max(0, -$net),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function attributes(string $name, int $itemId, int $cost, int $ts, ?string $note): array
    {
        $name = Bounds::text($name, 100);

        if ($name === null) {
            throw new InvalidArgumentException('Name the recipe.');
        }

        if ($cost < 1) {
            throw new InvalidArgumentException('Enter what the recipe cost.');
        }

        if (($problem = Bounds::tradeProblem($itemId, 1, $cost, $ts)) !== null) {
            throw new InvalidArgumentException(ucfirst($problem).'.');
        }

        return ['name' => $name, 'item_id' => $itemId, 'cost_copper' => $cost, 'bought_at' => Utc::sql($ts), 'note' => Bounds::note($note)];
    }
}
