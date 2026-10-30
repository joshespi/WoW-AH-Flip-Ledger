{{-- A signed ratio as a percentage, green above zero and red below (0.042 -> +4.2%). --}}
@props(['value' => null])

@if ($value === null)
    <span class="text-gray-400 dark:text-gray-500">—</span>
@else
    <span {{ $attributes->class(['tabular-nums', $value >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400']) }}>{{ ($value >= 0 ? '+' : '−').number_format(abs($value) * 100, 1) }}%</span>
@endif
