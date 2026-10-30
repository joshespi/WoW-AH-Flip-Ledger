{{-- The one <head> for every page (app and guest layouts, landing page, error pages).
     Pass $title for "Page · App name"; nothing from third parties is loaded. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if (request()->hasSession())
    {{-- Error pages can render before (or without) a session. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endif

<title>{{ filled($title ?? null) ? $title.' · '.config('app.name') : config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48">
<link rel="icon" href="{{ asset('logo.png') }}" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

@include('partials.theme-init')
@vite(['resources/css/app.css', 'resources/js/app.js'])
