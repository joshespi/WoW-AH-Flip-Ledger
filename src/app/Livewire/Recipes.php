<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Models\Recipe;
use App\Services\RecipesService;
use App\Services\TradesService;
use App\Support\Money;
use Illuminate\View\View;
use Livewire\Component;

class Recipes extends Component
{
    use ReportsErrors;

    /** A recipe id while editing, null while adding. */
    public ?int $editing = null;

    public string $name = '';

    public string $item = '';

    public string $cost = '';

    public string $time = '';

    public string $note = '';

    public function mount(): void
    {
        $this->time = auth()->user()->inputTime();
    }

    public function save(RecipesService $recipes, TradesService $trades): void
    {
        $user = auth()->user();

        $saved = $this->attempt('form', function () use ($recipes, $trades, $user) {
            $itemId = $trades->resolveItem($this->item, $user);
            $cost = Money::parseGold($this->cost);
            $ts = $user->parseInputTime($this->time);

            if ($this->editing === null) {
                $recipes->add($user, $this->name, $itemId, $cost, $ts, $this->note, $user->activeGame());
                $this->message = __('Recipe added.');
            } else {
                $recipes->update($user, $this->editing, $this->name, $itemId, $cost, $ts, $this->note);
                $this->message = __('Recipe updated.');
            }
        });

        if ($saved) {
            $this->resetForm();
        }
    }

    public function edit(int $id): void
    {
        $r = Recipe::ownedBy(auth()->user())->findOrFail($id);

        $this->resetErrorBag();
        $this->message = null;
        $this->editing = $r->id;
        $this->name = $r->name;
        $this->item = 'i:'.$r->item_id;
        $this->cost = Money::fmt($r->cost_copper);
        $this->time = auth()->user()->inputTime($r->bought_at);
        $this->note = (string) $r->note;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id, RecipesService $recipes): void
    {
        if (! $this->attempt('form', fn () => $recipes->delete(auth()->user(), $id))) {
            return;
        }

        if ($this->editing === $id) {
            $this->resetForm();
        }

        $this->message = __('Recipe deleted. Its crafts stay, no longer linked to it.');
    }

    public function render(RecipesService $recipes): View
    {
        $user = auth()->user();

        return view('livewire.recipes', ['rows' => $recipes->forUser($user, $user->activeGame()), 'user' => $user]);
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('editing', 'name', 'item', 'cost', 'note');
        $this->time = auth()->user()->inputTime();
    }
}
