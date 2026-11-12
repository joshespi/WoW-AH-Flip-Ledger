{{-- A figure with its label; `hint` is a string or a slot. --}}
@props(['label', 'hint' => null])

<div {{ $attributes->class(['card p-4']) }}>
    <p class="eyebrow">{{ $label }}</p>
    <p class="mt-2 font-mono text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-50">{{ $slot }}</p>
    @if ($hint)
        <p class="mt-1.5 note">{{ $hint }}</p>
    @endif
</div>
