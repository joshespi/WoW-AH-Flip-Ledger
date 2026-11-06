<?php

namespace App\Http\Controllers;

use App\Services\PositionsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionsController extends Controller
{
    public function index(Request $request, PositionsService $positions): View|RedirectResponse
    {
        // Both games live on the combined dashboard; this table is one game's.
        if ($request->user()->viewsAll()) {
            return redirect()->route('dashboard');
        }

        $portfolio = $positions->forUser($request->user(), $request->user()->activeGame());
        $open = $portfolio->open();
        $showClosed = $request->boolean('closed');

        return view('positions.index', [
            'portfolio' => $portfolio,
            'showClosed' => $showClosed,
            'rows' => $showClosed ? $portfolio->positions : $open,
            'closedCount' => count($portfolio->positions) - count($open),
            'user' => $request->user(),
        ]);
    }
}
