<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Models\Trade;
use App\Services\PositionsService;
use App\Services\TradesService;
use App\Support\Bounds;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The item page's "Correct holdings" card: set what the ledger holds now (removing the rest as a
 * fix or a write-off) and undo earlier corrections. Each write reloads the page, so the numbers,
 * chart and timeline around it are rebuilt.
 */
class CorrectHoldings extends Component
{
    use ReportsErrors;

    #[Locked]
    public int $itemId;

    /** What the ledger held when the page was built; display only, the service recomputes it. */
    #[Locked]
    public int $ledgerHeld;

    /** Units in the latest TSM inventory snapshot, null without one. */
    #[Locked]
    public ?int $tsmStock = null;

    public string $held = '0';

    public string $reason = Trade::SIDE_ADJUST;

    public string $note = '';

    public function mount(int $itemId, int $ledgerHeld, PositionsService $positions): void
    {
        $this->itemId = $itemId;
        $this->ledgerHeld = $ledgerHeld;
        $this->message = session('holdings');

        // The form only shows while something is held. TSM stock is a hint, not the default: the gap
        // is usually sales still in the mail, which would count as own-stock sales once collected.
        if ($ledgerHeld > 0) {
            $this->tsmStock = $positions->tsmStock(auth()->user(), $itemId, auth()->user()->activeGame());
        }
    }

    public function save(TradesService $trades): void
    {
        $saved = $this->attempt('correct', function () use ($trades) {
            $held = Bounds::wholeNumber($this->held) ?? throw new InvalidArgumentException('Held must be a whole number.');
            $trades->correctHeld(auth()->user(), $this->itemId, $held, $this->reason, $this->note, auth()->user()->activeGame());
        });

        if ($saved) {
            $this->reload(__('Holdings corrected.'));
        }
    }

    /** The correction being edited in its row, null when none. */
    public ?int $editing = null;

    public string $editQty = '';

    public string $editReason = '';

    public string $editNote = '';

    public function edit(int $id): void
    {
        $c = Trade::ownedBy(auth()->user())->whereIn('side', Trade::HOLDING_CORRECTIONS)->findOrFail($id);

        $this->editing = $c->id;
        $this->editQty = (string) $c->qty;
        $this->editReason = $c->side;
        $this->editNote = (string) $c->note;
        $this->resetErrorBag('corrections');
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag('corrections');
    }

    public function saveEdit(TradesService $trades): void
    {
        $saved = $this->attempt('corrections', function () use ($trades) {
            $qty = Bounds::wholeNumber($this->editQty) ?? throw new InvalidArgumentException('Removed must be a whole number.');
            $trades->updateCorrection(auth()->user(), (int) $this->editing, $qty, $this->editReason, $this->editNote);
        });

        if ($saved) {
            $this->reload(__('Correction updated.'));
        }
    }

    public function undo(int $id, TradesService $trades): void
    {
        if ($this->attempt('corrections', fn () => $trades->deleteCorrection(auth()->user(), $id))) {
            $this->reload(__('Correction undone.'));
        }
    }

    public function render(): View
    {
        $corrections = Trade::ownedBy(auth()->user())->inGame(auth()->user()->activeGame())->where('item_id', $this->itemId)->whereIn('side', Trade::CORRECTIONS)
            ->orderByDesc('traded_at')->orderByDesc('id')->limit(20)->get(['id', 'side', 'traded_at', 'qty', 'cost_copper', 'note']);

        return view('livewire.correct-holdings', ['corrections' => $corrections]);
    }

    private function reload(string $message): void
    {
        session()->flash('holdings', $message);
        $this->redirect(route('items.show', $this->itemId).'#correct');
    }
}
