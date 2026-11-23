<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SetupController extends Controller
{
    public function show(): View
    {
        return view('setup.show', ['addonUrl' => config('ledger.addon.url')]);
    }
}
