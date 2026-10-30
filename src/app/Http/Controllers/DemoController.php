<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    /** Signs in as the shared demo account; there is no password to guess. */
    public function __invoke(Request $request): RedirectResponse
    {
        $demo = User::where('is_demo', true)->first() ?? abort(404);

        Auth::login($demo);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
