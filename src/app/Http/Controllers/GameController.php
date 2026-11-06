<?php

namespace App\Http\Controllers;

use App\Support\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    /** Picks one enabled game, or all of them, for every screen. */
    public function switch(Request $request): RedirectResponse
    {
        $user = $request->user();
        $view = $request->validate(['game' => ['required', Rule::in([...$user->enabledGames(), Game::ALL])]])['game'];

        $user->setGameView($view);

        return back(fallback: route('dashboard'));
    }
}
