{{-- "Saved." that fades out after a moment; shown only when $when is true. --}}
@props(['when'])

@if ($when)
    <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2000)"
       {{ $attributes->class('text-sm text-gray-600 dark:text-gray-400') }}>{{ __('Saved.') }}</p>
@endif
