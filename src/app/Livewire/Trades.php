<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Models\Trade;
use App\Services\TradesService;
use App\Support\Bounds;
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

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->time = auth()->user()->inputTime();
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
            $itemId = $trades->resolveItem($this->item);
            $qty = Bounds::quantityInput($this->qty);
            $cost = Money::parseGold($this->cost);
            $ts = $user->parseInputTime($this->time);

            if ($this->editing === null) {
                $trades->add($user, $itemId, $qty, $cost, $ts, $this->note);
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
        $t = Trade::ownedBy(auth()->id())->where('side', Trade::SIDE_BUY)->findOrFail($id);

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

    public function addPasted(TradesService $trades): void
    {
        $added = 0;

        if (! $this->attempt('pasted', function () use ($trades, &$added) {
            $added = $trades->addPasted(auth()->user(), $this->pasted, time());
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

        $rows = DB::table('trades')
            ->leftJoin('items', 'items.id', '=', 'trades.item_id')
            ->ownedBy($user, 'trades.user_id')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->whereRaw('lower(items.name) like ?', [Sql::like($q)])
                ->when($id !== null && Bounds::validItemId($id), fn ($w) => $w->orWhere('trades.item_id', $id))))
            ->orderByDesc('trades.traded_at')->orderByDesc('trades.id')
            ->select('trades.id', 'trades.item_id', 'trades.traded_at', 'trades.qty', 'trades.cost_copper', 'trades.note', 'trades.source', 'trades.side', 'items.name')
            ->paginate(self::PER_PAGE);

        return view('livewire.trades', ['rows' => $rows, 'user' => $user]);
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editing', 'item', 'qty', 'cost', 'note');
        $this->time = auth()->user()->inputTime();
    }
}
