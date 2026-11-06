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
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#1c1d22" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f9fafb" media="(prefers-color-scheme: light)">
@php($blurb = __('Track your World of Warcraft auction house flips: realized profit and loss, open positions and live market prices, from your own recorded auctions or TradeSkillMaster data.'))
<meta name="description" content="{{ $blurb }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ filled($title ?? null) ? $title.' · '.config('app.name') : config('app.name') }}">
<meta property="og:description" content="{{ $blurb }}">
<meta property="og:image" content="{{ asset('logo.png') }}">
@unless (request()->is('/', 'about', 'privacy', 'terms'))
    {{-- Everything past the public pages is a private ledger. --}}
    <meta name="robots" content="noindex, nofollow">
@endunless

@include('partials.theme-init')
@vite(['resources/css/app.css', 'resources/js/app.js'])
