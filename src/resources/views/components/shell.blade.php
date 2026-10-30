{{-- The HTML document every layout shares: one <head>, and Livewire's styles and scripts (which also
     ship and start Alpine, so they load on every page, not just Livewire ones). --}}
@props(['title' => null, 'bodyClass' => ''])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title])
        @livewireStyles
    </head>
    <body class="{{ $bodyClass }}">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
