<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /** Works on one game at a time (the default): in All it shows the first enabled game. */
    public const GAMES_ONE = 'one';

    /** Also needs the price feed, which only Retail has. */
    public const GAMES_MARKET = 'market';

    /** Shows every enabled game, or does not depend on one. */
    public const GAMES_ANY = 'any';

    /**
     * @param  string|null  $title  page name for the browser tab ("Positions · WoW Flip Ledger")
     * @param  string  $games  how the page uses the game switch (GAMES_*), for `<x-game-banner>`
     */
    public function __construct(public ?string $title = null, public string $games = self::GAMES_ONE) {}

    public function render(): View
    {
        return view('layouts.app');
    }
}
