<?php

namespace App\Http\Controllers;

use App\Services\PositionsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PositionsService $positions): View
    {
        $user = $request->user();

        return view('dashboard', [
            'portfolio' => $positions->forUser($user),
            'user' => $user,
            'rows' => (int) config('ledger.positions.dashboard_rows'),
        ]);
    }
}
