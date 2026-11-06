{{-- One game, or all of them, for every screen. Lives in the always-dark sidebar; with a single
     enabled game there is nothing to switch, so it is not drawn at all. --}}
@php
    $user = auth()->user();
    $games = $user->enabledGames();
    $view = $user->gameView();
    // Pressed state per Game::tone() (and All); literal classes so Tailwind keeps them.
    $active = [
        'indigo' => 'bg-indigo-400/15 text-indigo-300 ring-1 ring-indigo-400/40',
        'teal' => 'bg-teal-400/15 text-teal-300 ring-1 ring-teal-400/40',
        'violet' => 'bg-violet-400/15 text-violet-300 ring-1 ring-violet-400/40',
        \App\Support\Game::ALL => 'bg-white/10 text-gray-100 ring-1 ring-white/20',
    ];
    $options = \App\Support\Game::labels($games) + [\App\Support\Game::ALL => __('All')];
@endphp

@if ($user->playsSeveral())
    <form method="POST" action="{{ route('game.switch') }}" class="px-3 pt-1" aria-label="{{ __('Game') }}">
        @csrf
        <p class="eyebrow px-1 pb-1">{{ __('Game') }}</p>
        <div @class(['grid gap-1 rounded-lg bg-black/20 p-1 text-center text-xs font-medium', 'grid-cols-3' => count($options) === 3, 'grid-cols-4' => count($options) === 4]) role="group">
            @foreach ($options as $value => $label)
                <button type="submit" name="game" value="{{ $value }}" aria-pressed="{{ $view === $value ? 'true' : 'false' }}"
                        class="rounded-md px-1 py-1.5 transition {{ $view === $value ? $active[$value === \App\Support\Game::ALL ? $value : \App\Support\Game::tone($value)] : 'text-gray-400 hover:bg-white/5 hover:text-gray-100' }}">{{ $label }}</button>
            @endforeach
        </div>
    </form>
@endif
