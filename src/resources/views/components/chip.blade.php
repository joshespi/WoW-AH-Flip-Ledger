{{-- A small pill. Always text, never color alone. `type` is a position status key (profit, underwater,
     below, above) or a label of your own, which takes the `tone` (green, red, amber, sky, indigo, gray; teal and violet for games). --}}
@props(['type', 'tone' => null])

@php
    $tones = [
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'sky' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
        'indigo' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
        // Game identity only (Support\Game::tone), the one exception to the single accent.
        'teal' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300',
        'violet' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300',
        'gray' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
    ];
    $known = [
        'profit' => ['In profit', 'green'],
        'underwater' => ['Under water', 'red'],
        'below' => ['Below typical', 'sky'],
        'above' => ['Above typical', 'amber'],
    ];
    [$label, $tone] = $known[$type] ?? [$type, $tone ?? 'gray'];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', $tones[$tone]]) }}>{{ __($label) }}</span>
