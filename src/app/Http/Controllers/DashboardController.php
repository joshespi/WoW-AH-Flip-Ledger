<?php

namespace App\Http\Controllers;

use App\Services\PositionsService;
use App\Services\RealizedRangesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PositionsService $positions, RealizedRangesService $ranges): View
    {
        $user = $request->user();
        $portfolio = $positions->forUser($user);

        return view('dashboard', [
            'portfolio' => $portfolio,
            'ranges' => $ranges->build($user, $portfolio->realizedEvents),
            'user' => $user,
            'rows' => (int) config('ledger.positions.dashboard_rows'),
        ]);
    }
}
