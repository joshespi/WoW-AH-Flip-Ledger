<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Services\ReconcileService;
use App\Support\Bounds;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Component;

class Reconcile extends Component
{
    use ReportsErrors;

    /** Candidate being corrected before it is accepted. */
    public ?int $editing = null;

    public string $qty = '';

    public string $cost = '';

    public string $time = '';

    /** Whether the candidate being edited is also sold to a vendor, at $vendorEach a unit. */
    public bool $vendoring = false;

    public string $vendorEach = '';

    /** @var list<int|string> Candidate ids ticked for a bulk action. */
    public array $selected = [];

    public function accept(int $id, ReconcileService $reconcile): void
    {
        if ($this->attempt('row'.$id, fn () => $reconcile->accept(auth()->user(), $id))) {
            $this->message = __('Buy added.');
        }
    }

    public function edit(int $id, ReconcileService $reconcile, bool $vendor = false): void
    {
        $user = auth()->user();
        $c = $reconcile->findPending($user, $id);

        if ($c === null) {
            return;
        }

        $this->resetErrorBag();
        $this->editing = $id;
        $this->qty = (string) $c->qty;
        $this->cost = Money::fmt((int) $c->cost_copper);
        $this->time = $user->inputTime($c->at);
        $this->vendoring = $vendor;
        $this->vendorEach = '';

        // Start from what the vendor paid last time for this item.
        if ($vendor && ($last = $reconcile->lastVendorUnit($user, (int) $c->item_id, $c->game)) !== null) {
            $this->vendorEach = Money::fmt($last);
        }
    }

    public function cancel(): void
    {
        $this->reset('editing', 'qty', 'cost', 'time', 'vendoring', 'vendorEach');
        $this->resetErrorBag();
    }

    public function saveEdited(ReconcileService $reconcile): void
    {
        $id = (int) $this->editing;
        $user = auth()->user();
        $vendoring = $this->vendoring;

        $saved = $this->attempt('row'.$id, function () use ($reconcile, $user, $id, $vendoring) {
            $qty = Bounds::quantityInput($this->qty);
            $cost = Money::parseGold($this->cost);
            $ts = $user->parseInputTime($this->time);

            return $vendoring
                ? $reconcile->acceptVendored($user, $id, Money::parseGold($this->vendorEach), $qty, $cost, $ts)
                : $reconcile->accept($user, $id, $qty, $cost, $ts);
        });

        if ($saved) {
            $this->cancel();
            $this->message = $vendoring ? __('Buy added and vendored.') : __('Buy added.');
        }
    }

    public function ignore(int $id, ReconcileService $reconcile): void
    {
        if (! $this->attempt('row'.$id, fn () => $reconcile->ignore(auth()->user(), $id))) {
            return;
        }

        if ($this->editing === $id) {
            $this->cancel();
        }

        $this->message = __('Suggestion ignored.');
    }

    public function acceptSelected(ReconcileService $reconcile): void
    {
        $n = 0;
        $ids = $this->selectedIds();

        if ($ids !== [] && $this->attempt('bulk', function () use ($reconcile, $ids, &$n) {
            $n = $reconcile->acceptMany(auth()->user(), $ids);
        })) {
            $this->bulkDone(count($ids), $n, ':n buy added.|:n buys added.', ':n skipped (implausible values or already handled).');
        }
    }

    public function vendorSelected(ReconcileService $reconcile): void
    {
        $n = 0;
        $ids = $this->selectedIds();

        if ($ids !== [] && $this->attempt('bulk', function () use ($reconcile, $ids, &$n) {
            $n = $reconcile->acceptVendoredMany(auth()->user(), $ids);
        })) {
            $this->bulkDone(count($ids), $n, ':n buy added and vendored.|:n buys added and vendored.',
                ':n skipped (no earlier vendor price for the item, or already handled). Use Vendored on a row to set one.');
        }
    }

    public function ignoreSelected(ReconcileService $reconcile): void
    {
        $n = 0;
        $ids = $this->selectedIds();

        if ($ids !== [] && $this->attempt('bulk', function () use ($reconcile, $ids, &$n) {
            $n = $reconcile->ignoreMany(auth()->user(), $ids);
        })) {
            $this->bulkDone(count($ids), $n, ':n suggestion ignored.|:n suggestions ignored.');
        }
    }

    /** After a bulk action on $ticked ids: clears the selection and says what was done and, if given, skipped. */
    private function bulkDone(int $ticked, int $done, string $doneText, ?string $skippedText = null): void
    {
        $this->reset('selected', 'editing');
        $this->message = trans_choice($doneText, $done, ['n' => $done])
            .($skippedText !== null && $ticked > $done ? ' '.__($skippedText, ['n' => $ticked - $done]) : '');
    }

    public function selectAll(ReconcileService $reconcile, bool $skipDoubles = false): void
    {
        $rows = $reconcile->pending(auth()->user(), auth()->user()->activeGame());
        $this->selected = array_column($skipDoubles ? array_filter($rows, fn (array $r): bool => ! $r['double']) : $rows, 'id');
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * At most a page's worth: the list comes from the client.
     *
     * @return list<int>
     */
    private function selectedIds(): array
    {
        $ids = array_filter(array_map('intval', $this->selected), fn (int $id): bool => $id > 0);

        return array_slice(array_values(array_unique($ids)), 0, ReconcileService::LIMIT);
    }

    public function render(ReconcileService $reconcile): View
    {
        $user = auth()->user();
        $rows = $reconcile->pending($user, $user->activeGame());
        $shown = array_column($rows, 'id');

        // Rows that were handled elsewhere drop out of the selection.
        $this->selected = array_values(array_intersect(array_map('intval', $this->selected), $shown));

        return view('livewire.reconcile', ['rows' => $rows, 'user' => $user, 'vendored' => $reconcile->vendoredTotals($user, $user->activeGame())]);
    }
}
