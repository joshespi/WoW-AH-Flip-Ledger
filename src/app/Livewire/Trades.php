<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Models\Recipe;
use App\Models\Trade;
use App\Models\User;
use App\Services\PositionsService;
use App\Services\TradesService;
use App\Support\Bounds;
use App\Support\Game;
use App\Support\ItemName;
use App\Support\Money;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Trades extends Component
{
    use ReportsErrors;
    use WithPagination;

    private const PER_PAGE = 25;

    // Add / edit form. `editing` is a trade id while editing a row, null while adding.
    public ?int $editing = null;

    public string $item = '';

    public string $qty = '';

    public string $cost = '';

    public string $time = '';

    public string $note = '';

    public string $pasted = '';

    private const MAX_INGREDIENTS = 20;

    private const CRAFT_SUGGESTION_LIMIT = 8;

    // Record-a-craft form: what is made, and each ingredient (a vendor one has a unit price in gold).
    public string $product = '';

    public string $made = '';

    public string $craftTime = '';

    public string $craftNote = '';

    /** '' picks the one recipe for the product, if any; 'none', or a recipe id. */
    public string $craftRecipe = '';

    /** @var list<array{item: string, qty: string, vendor: bool, price: string}> */
    public array $ingredients = [];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->time = $user->inputTime();
        $this->craftTime = $user->inputTime(seconds: true);
        $this->ingredients = [self::blankIngredient()];

        $recipeParam = request()->query('recipe');
        $recipeId = is_string($recipeParam) ? Bounds::wholeNumber($recipeParam) : null;
        if ($recipeId === null) {
            return;
        }

        $recipe = Recipe::ownedBy($user)->inGame($user->activeGame())->find($recipeId);
        if ($recipe !== null) {
            $this->product = 'i:'.$recipe->item_id;
            $this->craftRecipe = (string) $recipe->id;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** Known items whose name matches what is being typed in the item box. */
    #[Computed]
    public function suggestions(): array
    {
        $q = trim($this->item);

        if ($this->editing !== null || mb_strlen($q) < 2 || ItemName::bareId($q) !== null) {
            return [];
        }

        return DB::table('items')->whereRaw('lower(name) like ?', [Sql::like($q)])
            ->orderBy('name')->limit(8)->get(['id', 'name'])
            ->map(fn ($i) => ['id' => (int) $i->id, 'name' => $i->name])->all();
    }

    /** Per-unit price of the lot being typed, or null until qty and cost both read. */
    #[Computed]
    public function unitCopper(): ?int
    {
        $qty = Bounds::wholeNumber($this->qty);

        try {
            return $qty > 0 ? intdiv(Money::parseGold($this->cost) * 2 + $qty, $qty * 2) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function pick(int $id): void
    {
        $this->item = 'i:'.$id;
    }

    public function save(TradesService $trades): void
    {
        $user = auth()->user();

        $saved = $this->attempt('form', function () use ($trades, $user) {
            $itemId = $trades->resolveItem($this->item, $user);
            $qty = Bounds::quantityInput($this->qty);
            $cost = Money::parseGold($this->cost);
            $ts = $user->parseInputTime($this->time);

            if ($this->editing === null) {
                $trades->add($user, $itemId, $qty, $cost, $ts, $this->note, game: $user->activeGame());
                $this->message = __('Buy added.');
            } else {
                $trades->update($user, $this->editing, $itemId, $qty, $cost, $ts, $this->note);
                $this->message = __('Buy updated.');
            }
        });

        if ($saved) {
            $this->resetForm();
        }
    }

    public function edit(int $id): void
    {
        $t = Trade::ownedBy(auth()->id())->where('side', Trade::SIDE_BUY)->whereNull('craft_id')->findOrFail($id);

        $this->resetErrorBag();
        $this->message = null;
        $this->editing = $t->id;
        $this->item = 'i:'.$t->item_id;
        $this->qty = (string) $t->qty;
        $this->cost = Money::fmt($t->cost_copper);
        $this->time = auth()->user()->inputTime($t->traded_at);
        $this->note = (string) $t->note;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id, TradesService $trades): void
    {
        if (! $this->attempt('form', fn () => $trades->delete(auth()->user(), $id))) {
            return;
        }

        if ($this->editing === $id) {
            $this->resetForm();
        }

        $this->message = __('Deleted.');
    }

    public function addCraftRow(): void
    {
        $this->attempt('craft', function () {
            if (count($this->ingredients) >= self::MAX_INGREDIENTS) {
                throw new InvalidArgumentException('A craft can have at most '.self::MAX_INGREDIENTS.' ingredients.');
            }

            $this->ingredients[] = self::blankIngredient();
        });
    }

    #[Computed]
    public function craftStock(): array
    {
        $user = auth()->user();

        try {
            $ts = $user->parseInputTime($this->craftTime);
        } catch (InvalidArgumentException) {
            return [];
        }

        if (! Bounds::plausibleTime($ts)) {
            return [];
        }

        return app(PositionsService::class)->stockForCraft($user, $ts, $user->activeGame());
    }

    /** The latest unit price paid per vendor ingredient in this user's recorded crafts. */
    #[Computed]
    public function craftVendorPrices(): array
    {
        $user = auth()->user();
        $buys = DB::table('trades')->ownedBy($user)->inGame($user->activeGame())
            ->where('side', Trade::SIDE_BUY)->where('source', Trade::SOURCE_CRAFT)
            ->select('item_id', 'qty', 'cost_copper', 'traded_at', 'id')
            ->selectRaw('row_number() over (partition by item_id order by traded_at desc, id desc) as price_rank');

        return DB::query()->fromSub($buys, 'vendor_buys')->where('price_rank', 1)
            ->orderByDesc('traded_at')->orderByDesc('id')->get()
            ->mapWithKeys(fn ($buy) => [(int) $buy->item_id => Money::unit((int) $buy->cost_copper, (int) $buy->qty)])
            ->all();
    }

    #[Computed]
    public function craftItemNames(): array
    {
        $user = auth()->user();
        $sources = array_map('boolval', array_column($this->ingredients, 'vendor'));
        $ids = in_array(false, $sources, true) ? array_keys($this->craftStock) : [];

        if (in_array(true, $sources, true)) {
            $ids = [...$ids, ...array_keys($this->craftVendorPrices)];
        }

        foreach ([$this->product, ...array_column($this->ingredients, 'item')] as $value) {
            $id = ItemName::bareId(trim($value));
            if ($id !== null && Bounds::validItemId($id)) {
                $ids[] = $id;
            }
        }

        return ItemName::forUser($user, $user->activeGame(), $ids);
    }

    /** Item choices and, for an entered id, its name and the applicable stock or price hint. */
    public function craftItemPicker(string $value, ?bool $vendor = null): array
    {
        $user = auth()->user();
        $game = $user->activeGame();
        $query = trim($value);
        $id = ItemName::bareId($query);
        $stock = $vendor === false ? $this->craftStock : [];
        $prices = $vendor === true ? $this->craftVendorPrices : [];
        $choices = [];
        $selected = null;

        if ($id !== null && Bounds::validItemId($id)) {
            $name = $this->craftItemNames[$id] ?? null;
            $selected = ['name' => ItemName::display($name, $id), 'stock' => $stock[$id] ?? 0, 'price' => $prices[$id] ?? null];
        } elseif ($id === null) {
            $needle = mb_strtolower($query);
            foreach (array_keys($stock + $prices) as $candidate) {
                $name = ItemName::display($this->craftItemNames[$candidate] ?? null, $candidate);
                if (str_contains(mb_strtolower($name), $needle)) {
                    $choices[$candidate] = $name;
                }
                if (count($choices) === self::CRAFT_SUGGESTION_LIMIT) {
                    break;
                }
            }

            $remaining = self::CRAFT_SUGGESTION_LIMIT - count($choices);
            if ($remaining > 0 && mb_strlen($query) >= 2) {
                $pattern = Sql::like($query);
                $excluded = array_keys($choices);
                $personal = DB::table('user_item_names')->ownedBy($user)->inGame($game);
                $matches = (clone $personal)->whereRaw('lower(name) like ?', [$pattern])
                    ->whereNotIn('item_id', $excluded)->selectRaw('item_id as id, name');

                if (Game::hasMarket($game)) {
                    $matches->unionAll(DB::table('items')->whereRaw('lower(name) like ?', [$pattern])
                        ->whereNotIn('id', $excluded)->whereNotIn('id', $personal->select('item_id'))->select('id', 'name'));
                }

                $choices += $matches->orderBy('name')->orderBy('id')->limit($remaining)->pluck('name', 'id')->all();
            }
        }

        return compact('selected', 'choices', 'stock', 'prices', 'id');
    }

    public function pickCraftItem(int $id, ?int $row = null): void
    {
        if (! Bounds::validItemId($id)) {
            return;
        }

        if ($row === null) {
            $product = 'i:'.$id;
            $this->clearRecipeForProductChange($product);
            $this->product = $product;

            return;
        }

        if (! isset($this->ingredients[$row])) {
            return;
        }

        $ingredient = &$this->ingredients[$row];
        $ingredient['item'] = 'i:'.$id;
        $price = $ingredient['vendor'] ? ($this->craftVendorPrices[$id] ?? null) : null;
        $ingredient['price'] = $price === null ? '' : Money::fmt($price);
    }

    public function updatingIngredients(mixed $value, ?string $key): void
    {
        // A price entered or picked for one item must not follow a different item.
        if ($key !== null && preg_match('/^(\d+)\.item$/D', $key, $parts) && isset($this->ingredients[$parts[1]])) {
            $this->ingredients[$parts[1]]['price'] = '';
        }
    }

    public function updatingProduct(string $value): void
    {
        $this->clearRecipeForProductChange($value);
    }

    private function clearRecipeForProductChange(string $value): void
    {
        $current = trim($this->product);
        $next = trim($value);

        if (Bounds::wholeNumber($this->craftRecipe) !== null
            && (ItemName::bareId($current) ?? $current) !== (ItemName::bareId($next) ?? $next)) {
            $this->craftRecipe = '';
        }
    }

    public function removeCraftRow(int $i): void
    {
        $this->attempt('craft', function () use ($i) {
            unset($this->ingredients[$i]);
            $this->ingredients = array_values($this->ingredients ?: [self::blankIngredient()]);
        });
    }

    public function saveCraft(TradesService $trades): void
    {
        $user = auth()->user();

        $saved = $this->attempt('craft', function () use ($trades, $user) {
            $rows = [];

            if (count($this->ingredients) > self::MAX_INGREDIENTS) {
                throw new InvalidArgumentException('A craft can have at most '.self::MAX_INGREDIENTS.' ingredients.');
            }

            foreach ($this->ingredients as $n => $i) {
                $line = $n + 1;
                $i = (array) $i + self::blankIngredient();
                $i['item'] = (string) $i['item'];
                $i['qty'] = (string) $i['qty'];
                $i['price'] = (string) $i['price'];

                try {
                    $rows[] = [
                        'item' => $trades->resolveItem($i['item'], $user),
                        'qty' => Bounds::quantityInput($i['qty']),
                        'vendor' => $i['vendor'] ? Money::parseGold($i['price']) : null,
                    ];
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException("Ingredient {$line}: ".$e->getMessage());
                }
            }

            $productId = $trades->resolveItem($this->product, $user);
            $recipeId = match ($this->craftRecipe) {
                'none' => null,
                '' => $this->onlyRecipeFor($user, $productId),
                default => Bounds::wholeNumber($this->craftRecipe) ?? throw new InvalidArgumentException('Pick one of your recipes.'),
            };

            $trades->addCraft($user, $productId, Bounds::quantityInput($this->made), $rows,
                $user->parseInputTime($this->craftTime), $this->craftNote, $user->activeGame(), $recipeId);
            $this->message = __('Craft recorded.');
        });

        if ($saved) {
            $this->reset('product', 'made', 'craftNote', 'craftRecipe');
            $this->ingredients = [self::blankIngredient()];
            $this->craftTime = $user->inputTime(seconds: true);
        }
    }

    public function deleteCraft(int $craftId, TradesService $trades): void
    {
        if ($this->attempt('form', fn () => $trades->deleteCraft(auth()->user(), $craftId))) {
            $this->message = __('Craft deleted.');
        }
    }

    /** The user's recipe for the product when there is exactly one in this game, else null. */
    private function onlyRecipeFor(User $user, int $productId): ?int
    {
        $ids = Recipe::ownedBy($user)->inGame($user->activeGame())->where('item_id', $productId)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids[0] : null;
    }

    private static function blankIngredient(): array
    {
        return ['item' => '', 'qty' => '', 'vendor' => false, 'price' => ''];
    }

    public function addPasted(TradesService $trades): void
    {
        $added = 0;

        if (! $this->attempt('pasted', function () use ($trades, &$added) {
            $added = $trades->addPasted(auth()->user(), $this->pasted, time(), auth()->user()->activeGame());
        })) {
            return;
        }

        $this->pasted = '';
        $this->message = trans_choice(':n buy added from your paste.|:n buys added from your paste.', $added, ['n' => $added]);
    }

    public function render(): View
    {
        $user = auth()->user();
        $q = trim($this->search);
        // Only a bare id ("242639", "i:242639") searches by id, so "Tier 3" doesn't match item 3.
        $id = ItemName::bareId($q);

        $game = $user->activeGame();

        $rows = ItemName::joinUser(
            DB::table('trades')
                ->leftJoin('items', 'items.id', '=', 'trades.item_id')
                ->ownedBy($user, 'trades.user_id')->inGame($game, 'trades.game')
                ->select('trades.id', 'trades.item_id', 'trades.traded_at', 'trades.qty', 'trades.cost_copper', 'trades.note', 'trades.source', 'trades.side', 'trades.craft_id'),
            $user, $game, 'trades.item_id')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->whereRaw('lower('.ItemName::nameSql($game).') like ?', [Sql::like($q)])
                ->when($id !== null && Bounds::validItemId($id), fn ($w) => $w->orWhere('trades.item_id', $id))))
            ->orderByDesc('trades.traded_at')->orderByDesc('trades.id')
            // A reconciled buy with a vendor sale (same tsm_ref), so the delete warning can say so.
            ->selectSub(DB::table('trades as v')->whereColumn('v.user_id', 'trades.user_id')->whereColumn('v.game', 'trades.game')
                ->whereColumn('v.tsm_ref', 'trades.tsm_ref')->where('v.side', Trade::SIDE_VENDOR)->selectRaw('count(*)'), 'vendored')
            ->paginate(self::PER_PAGE);

        $recipes = Recipe::ownedBy($user)->inGame($game)->orderBy('name')->get(['id', 'name']);

        return view('livewire.trades', ['rows' => $rows, 'user' => $user, 'recipes' => $recipes]);
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editing', 'item', 'qty', 'cost', 'note');
        $this->time = auth()->user()->inputTime();
    }
}
