{{-- A stored time (date, epoch or UTC database string) in the signed-in user's timezone; a dash when null. Signed-in pages only (no guest pages, no mail). --}}
@props(['at' => null, 'format' => 'Y-m-d H:i'])

@php($t = $at === null ? null : auth()->user()->local($at))

@if ($t === null)
    <x-num />
@else
    <time datetime="{{ $t->toIso8601String() }}" {{ $attributes->class(['tabular-nums']) }}>{{ $t->format($format) }}</time>
@endif
