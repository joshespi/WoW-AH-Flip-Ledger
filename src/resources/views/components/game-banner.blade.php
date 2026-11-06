{{-- Under the freshness strip: says which game the page shows when that is not plainly Retail, and
     where a page cannot follow the switch. Nothing at all for a user who plays one game. `games`
     is how the page uses the switch (AppLayout::GAMES_*). --}}
@use('App\Support\Game')
@use('App\View\Components\AppLayout')
@props(['games' => AppLayout::GAMES_ONE])

@php
    $user = auth()->user();
    $view = $user->gameView();
    $active = $user->activeGame();
    $note = match (true) {
        $view === Game::ALL && $games !== AppLayout::GAMES_ANY => __('This page works on one game at a time and is showing :game. Pick a game to work in another ledger.', ['game' => Game::label($active)]),
        $games === AppLayout::GAMES_MARKET && ! Game::hasMarket($active) => __(':game has no price feed yet, so market numbers here are Retail only.', ['game' => Game::label($active)]),
        default => null,
    };
@endphp

@if ($note || $view !== $user->enabledGames()[0])
    <div class="px-4 pt-4 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            @if ($view === Game::ALL)
                @foreach ($user->enabledGames() as $g)<x-game-badge :game="$g" />@endforeach
            @else
                <x-game-badge :game="$view" />
            @endif
            @if ($note)<span>{{ $note }}</span>@endif
        </div>
    </div>
@endif
