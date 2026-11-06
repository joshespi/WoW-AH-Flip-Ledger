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

    /** @var list<int|string> Candidate ids ticked for a bulk action. */
    public array $selected = [];

    public function accept(int $id, ReconcileService $reconcile): void
    {
        if ($this->attempt('row'.$id, fn () => $reconcile->accept(auth()->user(), $id))) {
            $this->message = __('Buy added.');
        }
    }

    public function edit(int $id, ReconcileService $reconcile): void
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
    }

    public function cancel(): void
    {
        $this->reset('editing', 'qty', 'cost', 'time');
        $this->resetErrorBag();
    }

    public function saveEdited(ReconcileService $reconcile): void
    {
        $id = (int) $this->editing;
        $user = auth()->user();

        $saved = $this->attempt('row'.$id, fn () => $reconcile->accept(
            $user, $id, Bounds::quantityInput($this->qty), Money::parseGold($this->cost), $user->parseInputTime($this->time),
        ));

        if ($saved) {
            $this->cancel();
            $this->message = __('Buy added.');
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
        $result = ['accepted' => 0, 'skipped' => 0];
        $ids = $this->selectedIds();

        if ($ids === [] || ! $this->attempt('bulk', function () use ($reconcile, $ids, &$result) {
            $result = $reconcile->acceptMany(auth()->user(), $ids);
        })) {
            return;
        }

        $this->reset('selected', 'editing');
        $this->message = trans_choice(':n buy added.|:n buys added.', $result['accepted'], ['n' => $result['accepted']])
            .($result['skipped'] > 0 ? ' '.__(':n skipped (implausible values or already handled).', ['n' => $result['skipped']]) : '');
    }

    public function ignoreSelected(ReconcileService $reconcile): void
    {
        $n = 0;
        $ids = $this->selectedIds();

        if ($ids === [] || ! $this->attempt('bulk', function () use ($reconcile, $ids, &$n) {
            $n = $reconcile->ignoreMany(auth()->user(), $ids);
        })) {
            return;
        }

        $this->reset('selected', 'editing');
        $this->message = trans_choice(':n suggestion ignored.|:n suggestions ignored.', $n, ['n' => $n]);
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

        return view('livewire.reconcile', ['rows' => $rows, 'user' => $user]);
    }
}
