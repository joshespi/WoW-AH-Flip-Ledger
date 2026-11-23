{{-- Scrollable table region: keyboard-focusable and named for screen readers. Extra attributes (x-data, max-h-*) pass through. --}}
@props(['label'])

<div {{ $attributes->class('table-scroll') }} tabindex="0" role="region" aria-label="{{ $label }}">
    {{ $slot }}
</div>
