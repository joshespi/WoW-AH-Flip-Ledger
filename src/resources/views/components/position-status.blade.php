{{-- A position's chips: profit / under water, then below / above typical. --}}
@props(['position'])

@if ($position->status || $position->market)
    <span {{ $attributes->class(['inline-flex flex-wrap gap-1']) }}>
        @if ($position->status)<x-chip :type="$position->status" />@endif
        @if ($position->market)<x-chip :type="$position->market" />@endif
    </span>
@else
    <span class="text-gray-400 dark:text-gray-500">—</span>
@endif
