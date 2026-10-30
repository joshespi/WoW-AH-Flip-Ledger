{{-- A plain button, or a link styled as one when given an href. --}}
@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class('btn-secondary') }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-secondary']) }}>
        {{ $slot }}
    </button>
@endif
