{{-- A plain number, or the same dash <x-money> shows when there is none. --}}
@props(['value' => null, 'decimals' => 0, 'suffix' => ''])

@if ($value === null)
    <span class="text-gray-400 dark:text-gray-500">—</span>
@else
    {{ number_format($value, $decimals).$suffix }}
@endif
