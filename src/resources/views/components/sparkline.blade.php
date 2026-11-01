{{-- Tiny trend line from a list of values (oldest first). Needs two points; colour follows the move. --}}
@props(['values' => []])

@if (count($values) >= 2)
    @php
        $min = min($values);
        $span = max(1, max($values) - $min);
        $n = count($values) - 1;
        $points = collect($values)->map(fn ($v, $i) => round($i / $n * 80, 1).','.round(22 - ($v - $min) / $span * 20, 1))->implode(' ');
        $up = end($values) >= $values[0];
    @endphp
    <svg viewBox="0 0 80 24" class="h-6 w-20 text-gray-400" role="img" aria-label="{{ $up ? __('Trending up') : __('Trending down') }}">
        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
@else
    <span class="text-gray-400">—</span>
@endif
