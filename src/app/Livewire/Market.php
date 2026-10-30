<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Models\WatchlistItem;
use App\Services\TradesService;
use App\Services\WatchlistService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Component;

class Market extends Component
{
    use ReportsErrors;

    public string $item = '';

    public string $group = '';

    // Row being edited.
    public ?int $editing = null;

    public string $buy = '';

    public string $sell = '';

    public string $editGroup = '';

    public string $notes = '';

    public function add(TradesService $items, WatchlistService $watchlist): void
    {
        if ($this->attempt('add', fn () => $watchlist->add(auth()->user(), $items->resolveItem($this->item), $this->group))) {
            $this->reset('item');
            $this->message = __('Added to your watchlist.');
        }
    }

    public function edit(int $id): void
    {
        $row = WatchlistItem::ownedBy(auth()->id())->find($id);

        if ($row === null) {
            return;
        }

        $this->resetErrorBag();
        $this->editing = $id;
        $this->buy = $row->target_buy_copper === null ? '' : Money::fmt($row->target_buy_copper);
        $this->sell = $row->target_sell_copper === null ? '' : Money::fmt($row->target_sell_copper);
        $this->editGroup = (string) $row->group_name;
        $this->notes = (string) $row->notes;
    }

    public function cancel(): void
    {
        $this->reset('editing', 'buy', 'sell', 'editGroup', 'notes');
        $this->resetErrorBag();
    }

    public function save(WatchlistService $watchlist): void
    {
        if ($this->attempt('edit', fn () => $watchlist->update(auth()->user(), (int) $this->editing, $this->buy, $this->sell, $this->editGroup, $this->notes))) {
            $this->cancel();
        }
    }

    public function remove(int $id, WatchlistService $watchlist): void
    {
        if ($this->attempt('list', fn () => $watchlist->remove(auth()->user(), $id)) && $this->editing === $id) {
            $this->cancel();
        }
    }

    public function move(int $id, int $direction, WatchlistService $watchlist): void
    {
        $this->attempt('list', fn () => $watchlist->move(auth()->user(), $id, $direction));
    }

    public function render(WatchlistService $watchlist): View
    {
        return view('livewire.market', ['rows' => $watchlist->rows(auth()->user())]);
    }
}
