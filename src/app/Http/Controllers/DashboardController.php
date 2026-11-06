<?php

namespace App\Http\Controllers;

use App\Services\CombinedService;
use App\Services\PositionsService;
use App\Services\RealizedRangesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PositionsService $positions, RealizedRangesService $ranges, CombinedService $combined): View
    {
        $user = $request->user();

        if ($user->viewsAll()) {
            return view('combined', ['data' => $combined->forUser($user), 'user' => $user, 'rows' => (int) config('ledger.positions.dashboard_rows')]);
        }

        $game = $user->activeGame();
        $portfolio = $positions->forUser($user, $game);

        return view('dashboard', [
            'portfolio' => $portfolio,
            'ranges' => $ranges->build($user, $portfolio->realizedEvents),
            'user' => $user,
            'game' => $game,
            'rows' => (int) config('ledger.positions.dashboard_rows'),
        ]);
    }
}
