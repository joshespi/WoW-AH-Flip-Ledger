{{-- Names the game a figure or row belongs to. Text always, colour second (Support\Game::tone). --}}
@props(['game'])

<x-chip :type="\App\Support\Game::label($game)" :tone="\App\Support\Game::tone($game)" {{ $attributes }} />
