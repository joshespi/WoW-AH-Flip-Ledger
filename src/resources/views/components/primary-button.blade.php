{{-- A submit button, or a link styled as one when given an href. --}}
@props(['href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class('btn-primary') }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary']) }}>
        {{ $slot }}
    </button>
@endif
