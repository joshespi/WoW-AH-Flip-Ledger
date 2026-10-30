{{-- Copper as colored gold / silver / copper, from the same Money::segments() rules as Money::fmt()
     (the title carries the plain text). `pnl` adds an explicit + / - so profit and loss read
     at a glance without relying on color alone. --}}
@props(['copper' => null, 'pnl' => false])

@if ($copper === null)
    <span class="text-gray-400 dark:text-gray-500" title="{{ __('No data yet') }}">—</span>
@else
    @php
        ['sign' => $sign, 'units' => $units] = \App\Support\Money::segments($copper);
        $colors = ['g' => 'text-amber-600 dark:text-indigo-300', 's' => 'text-gray-500 dark:text-gray-300', 'c' => 'text-orange-700 dark:text-orange-400'];
        $mark = match (true) {
            $sign === '-' => ['−', 'text-red-600 dark:text-red-400'],
            $pnl && \App\Support\Money::round($copper) !== 0 => ['+', 'text-green-600 dark:text-green-400'],
            default => null,
        };
        $digits = implode(' ', array_map(fn (array $u) => '<span class="'.$colors[$u[0]].'">'.e($u[1].$u[0]).'</span>', $units));
    @endphp
    <span {{ $attributes->class(['whitespace-nowrap font-mono tabular-nums']) }} title="{{ \App\Support\Money::fmt($copper) }}">@if ($mark)<span class="{{ $mark[1] }}">{{ $mark[0] }}</span>@endif{!! $digits !!}</span>
@endif
