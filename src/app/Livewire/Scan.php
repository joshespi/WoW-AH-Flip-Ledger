<?php

namespace App\Livewire;

use App\Livewire\Concerns\ReportsErrors;
use App\Services\ScanService;
use App\Services\WatchlistService;
use App\Support\Bounds;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class Scan extends Component
{
    use ReportsErrors;

    #[Url(except: '')]
    public string $minItemId = '';

    #[Url(except: '')]
    public string $minSold = '';

    /** Percent under typical, e.g. 25 means floor <= 75% of typical. */
    #[Url(except: '')]
    public string $minDiscount = '';

    #[Url(except: '')]
    public string $top = '';

    /** One of ScanService::MODES. */
    #[Url(except: 'discount')]
    public string $mode = 'discount';

    /** Column override on top of the ranking; '' keeps the ranking's own order. */
    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    private const SORTABLE = ['name', 'floor', 'recent', 'typical', 'ratio', 'margin', 'roi', 'sold'];

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        // Same column flips; a new column starts descending (ascending for the name).
        $this->dir = $this->sort === $column ? ($this->dir === 'desc' ? 'asc' : 'desc') : ($column === 'name' ? 'asc' : 'desc');
        $this->sort = $column;
    }

    /** Picking a ranking means its own order, not the last column clicked. */
    public function updatedMode(): void
    {
        $this->sort = '';
        $this->dir = 'desc';
    }

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

        return view('livewire.scan', ['rows' => $this->sorted($rows), 'days' => $rows === [] ? $scan->daysCollected(auth()->user()) : 0, 'modes' => $modes, 'activeMode' => $mode]);
    }

    /** Rows without a value sink to the bottom whichever way it sorts. */
    private function sorted(array $rows): array
    {
        $col = $this->sort;

        // `sort` can arrive from the query string.
        if (! in_array($col, self::SORTABLE, true)) {
            return $rows;
        }

        $sign = $this->dir === 'asc' ? 1 : -1;
        $key = fn (array $r) => $col === 'name' ? mb_strtolower((string) ($r['name'] ?? '')) ?: null : $r[$col];

        usort($rows, function (array $a, array $b) use ($key, $sign) {
            [$x, $y] = [$key($a), $key($b)];

            return $x === null || $y === null ? ($x === null) <=> ($y === null) : $sign * ($x <=> $y);
        });

        return $rows;
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
