<?php

namespace App\Http\Controllers;

use App\Services\ScoreboardService;
use App\Support\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ScoreboardController extends Controller
{
    public function __invoke(Request $request, ScoreboardService $scoreboard): View
    {
        // Query values come from links, but anyone can type them: anything unknown falls back.
        $pick = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : $allowed[0];

        $game = $pick('game', Game::GAMES);
        $period = $pick('period', array_keys(ScoreboardService::PERIODS));
        $metric = $pick('metric', array_keys(ScoreboardService::METRICS));

        $rows = $scoreboard->board($game, $period, $metric);
        $user = $request->user();
        $updated = $rows === [] ? null : max(array_column($rows, 'computed_at'));

        return view('pages.scoreboard', [
            'rows' => $rows,
            'game' => $game,
            'period' => $period,
            'metric' => $metric,
            'games' => $scoreboard->games(),
            'updated' => $updated === null ? null : ($user ? $user->local($updated) : Carbon::parse($updated, 'UTC')),
            'me' => $user?->id,
        ]);
    }
}
