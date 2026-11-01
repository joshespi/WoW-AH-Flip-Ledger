<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Services\ScanService;
use App\Services\WatchlistService;
use App\Support\Bounds;
use Illuminate\View\View;
use Livewire\Component;

class Scan extends Component
{
    use ReportsErrors;

    public string $minItemId = '';

    public string $minSold = '';

    /** Percent under typical, e.g. 25 means floor <= 75% of typical. */
    public string $minDiscount = '';

    public string $top = '';

    /** One of ScanService::MODES. */
    public string $mode = 'discount';

    public function add(int $itemId, WatchlistService $watchlist): void
    {
        if ($this->attempt('add', fn () => $watchlist->add(auth()->user(), $itemId))) {
            $this->message = __('Added to your watchlist.');
        }
    }

    public function render(ScanService $scan): View
    {
        $modes = $this->modes();
        $mode = array_key_exists($this->mode, $modes) ? $this->mode : 'discount';
        $discount = is_numeric($this->minDiscount) ? max(0.0, min(99.0, (float) $this->minDiscount)) : null;

        $rows = $scan->scan(
            auth()->user(),
            Bounds::wholeNumber($this->minItemId) ?? 0,
            Bounds::wholeNumber($this->minSold) ?? 0,
            $discount === null ? null : 1 - $discount / 100,
            Bounds::wholeNumber($this->top) ?: null,
            $mode,
        );

        return view('livewire.scan', ['rows' => $rows, 'days' => $rows === [] ? $scan->daysCollected(auth()->user()) : 0, 'modes' => $modes, 'activeMode' => $mode]);
    }

    /** @return array<string, string> */
    private function modes(): array
    {
        return [
            'discount' => __('Cheapest vs typical'),
            'margin' => __('Best margin'),
            'volume' => __('Fast sellers'),
            'winners' => __('Your past winners'),
        ];
    }
}
