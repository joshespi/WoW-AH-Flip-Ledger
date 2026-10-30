<?php

namespace App\Http\Controllers;

use App\Services\PositionsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionsController extends Controller
{
    public function index(Request $request, PositionsService $positions): View
    {
        $portfolio = $positions->forUser($request->user());
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
