{{-- A figure with its label; `hint` is a string or a slot. --}}
@props(['label', 'hint' => null, 'featured' => false])

<div {{ $attributes->class(['card p-4', 'metric-featured' => $featured]) }}>
    <p class="eyebrow">{{ $label }}</p>
    <p class="metric-value">{{ $slot }}</p>
    @if ($hint)
        <p class="mt-1.5 note">{{ $hint }}</p>
    @endif
</div>
