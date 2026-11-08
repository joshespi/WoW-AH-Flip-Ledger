<?php

namespace App\Http\Controllers;

use App\Services\SiteStatsService;
use Illuminate\View\View;

class StatsController extends Controller
{
    public function __invoke(SiteStatsService $stats): View
    {
        return view('pages.stats', ['stats' => $stats->get()]);
    }
}
