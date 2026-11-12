@php
    // [section label, [[label, route, active pattern, icon], ...]]; one list for the desktop sidebar and the mobile drawer.
    $menu = [
        [null, [
            [__('Dashboard'), 'dashboard', 'dashboard', 'grid'],
        ]],
        [__('Ledger'), [
            [__('Positions'), 'positions.index', 'positions.*', 'chart'],
            [__('Trades'), 'trades.index', 'trades.*', 'swap'],
            [__('Reconcile'), 'reconcile.index', 'reconcile.*', 'check'],
        ]],
        [__('Market'), [
            [__('Watchlist'), 'market.index', 'market.*', 'eye'],
            [__('Scan'), 'scan.index', 'scan.*', 'search'],
            [__('Calculator'), 'calculator.index', 'calculator.*', 'calc'],
        ]],
        [__('Data'), [
            [__('Uploads'), 'uploads.index', 'uploads.*', 'upload'],
        ]],
        [__('Community'), [
            [__('Stats'), 'stats', 'stats', 'bars'],
            [__('Scoreboard'), 'scoreboard', 'scoreboard', 'trophy'],
        ]],
    ];

    if (auth()->user()?->is_admin) {
        $menu[] = [__('Admin'), [[__('Admin'), 'admin.overview', 'admin.*', 'shield']]];
    }

    $icons = [
        'grid' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'chart' => 'M3 3v18h18M7 14l4-4 3 3 5-6',
        'swap' => 'M7 7h13m0 0-4-4m4 4-4 4M17 17H4m0 0 4-4m-4 4 4 4',
        'check' => 'm9 12 2 2 4-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
        'eye' => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'search' => 'm21 21-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z',
        'calc' => 'M7 3h10a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM9 7h6M9 12h.01M12 12h.01M15 12h.01M9 16h.01M12 16h.01M15 16h.01',
        'upload' => 'M12 16V4m0 0L8 8m4-4 4 4M4 20h16',
        'bars' => 'M5 21V11M12 21V4M19 21v-7',
        'trophy' => 'M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4zM17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z',
    ];
@endphp

<div x-data="{ open: false }" @keydown.escape.window="open = false">
    {{-- Mobile top bar --}}
    <div class="dark lg:hidden sticky top-0 z-30 flex h-14 items-center justify-between border-b border-gray-800 bg-gray-900 px-4">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold text-gray-100">
            <x-application-logo class="h-7 w-7 rounded-md" />
            <span>{{ config('app.name') }}</span>
        </a>
        <button type="button" @click="open = true" class="-me-2 rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-gray-100" aria-label="{{ __('Open menu') }}">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
    </div>

    <div x-show="open" x-cloak x-transition.opacity @click="open = false" class="fixed inset-0 z-40 bg-black/60 lg:hidden"></div>

    {{-- Sidebar: always dark, whatever the page theme --}}
    <aside class="dark fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-e border-gray-800 bg-gray-900 transition-transform duration-200 lg:translate-x-0"
           :class="{ 'translate-x-0': open }" aria-label="{{ __('Main') }}">
        <div class="flex h-16 shrink-0 items-center justify-between px-5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 text-gray-100">
                <x-application-logo class="h-8 w-8 rounded-lg" />
                <span class="leading-tight">
                    <span class="block text-sm font-semibold tracking-tight">{{ config('app.name') }}</span>
                    <span class="block text-[10px] uppercase tracking-[0.14em] text-indigo-400">{{ __('AH flips') }}</span>
                </span>
            </a>
            <button type="button" @click="open = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-white/5 lg:hidden" aria-label="{{ __('Close menu') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6" /></svg>
            </button>
        </div>

        <x-game-switcher />

        <nav class="grow space-y-5 overflow-y-auto px-3 py-3">
            @foreach ($menu as [$section, $links])
                <div class="space-y-0.5">
                    @if ($section)
                        <p class="eyebrow px-3 pb-1">{{ $section }}</p>
                    @endif
                    @foreach ($links as [$label, $route, $pattern, $icon])
                        <a href="{{ route($route) }}" class="side-link" @if (request()->routeIs($pattern)) aria-current="page" @endif>
                            <svg class="h-[18px] w-[18px] shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$icon] }}" /></svg>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="shrink-0 space-y-3 border-t border-gray-800 p-3">
            <x-theme-toggle class="w-full justify-between" />

            <div class="px-2">
                <p class="truncate text-sm font-medium text-gray-100">{{ Auth::user()->name }}</p>
                <p class="truncate text-xs text-gray-500">{{ Auth::user()->email }}</p>
            </div>

            <div class="grid grid-cols-3 gap-1 text-center text-xs font-medium">
                <a href="{{ route('profile.edit') }}" class="rounded-md px-2 py-1.5 text-gray-400 hover:bg-white/5 hover:text-gray-100">{{ __('Profile') }}</a>
                <a href="{{ route('settings.edit') }}" class="rounded-md px-2 py-1.5 text-gray-400 hover:bg-white/5 hover:text-gray-100">{{ __('Settings') }}</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="w-full rounded-md px-2 py-1.5 text-gray-400 hover:bg-white/5 hover:text-gray-100">{{ __('Log Out') }}</button>
                </form>
            </div>
        </div>
    </aside>
</div>
