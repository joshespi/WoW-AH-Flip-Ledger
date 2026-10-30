<?php

namespace App\Livewire;

use App\Support\Bounds;
use App\Support\Money;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;

/** Pre-buy check: what to list at to clear a margin over a per-item cost. Reads nothing, writes nothing. */
class Calculator extends Component
{
    /** Quick margins; the user's own target is merged in. */
    private const MARGINS = [0, 5, 10, 15, 25];

    public string $cost = '';

    public string $quantity = '';

    public function render(): View
    {
        $target = (float) auth()->user()->target_margin_pct;
        $margins = array_unique([...self::MARGINS, $target]);
        sort($margins);

        $cost = null;
        $error = null;

        if (trim($this->cost) !== '') {
            try {
                $cost = Money::parseGold($this->cost);
                if ($cost < 1 || $cost > Bounds::MAX_PRICE) {
                    $cost = null;
                    $error = __('That cost is out of range.');
                }
            } catch (InvalidArgumentException) {
                $error = __('Not a gold amount. Try 5g25s55c or 5.2555.');
            }
        }

        $qty = Bounds::count(trim($this->quantity), Bounds::MAX_QTY) ?: null;
        $rows = [];

        foreach ($cost === null ? [] : $margins as $m) {
            $sell = (int) ceil(Money::sellPrice($cost, $m));
            $profit = Money::round($sell * (1 - Money::AH_CUT)) - $cost;

            $rows[] = [
                'margin' => $m,
                'target' => (float) $m === $target,
                'sell' => $sell,
                'profit' => $profit,
                'stack' => $qty === null ? null : $profit * $qty,
            ];
        }

        return view('livewire.calculator', [
            'rows' => $rows,
            'error' => $error,
            'outlay' => $cost !== null && $qty !== null ? $cost * $qty : null,
        ]);
    }
}
