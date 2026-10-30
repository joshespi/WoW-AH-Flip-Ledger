<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * An admin can look at an account as its owner sees it. The session is read-only (see
 * ReadOnlySession and User::readOnly), never reaches another admin or the demo account, and
 * both ends are written to the audit trail.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, AdminService $admin, User $user): RedirectResponse
    {
        $me = $request->user();

        if ($user->is_admin || $user->is_demo || $user->is($me)) {
            return back()->withErrors(['impersonate' => 'That account cannot be viewed this way.']);
        }

        // Every screen sits behind email verification, so there is nothing to look at, and the
        // verify-email page has no way back.
        if (! $user->hasVerifiedEmail()) {
            return back()->withErrors(['impersonate' => 'That account has not verified its email yet, so there is nothing to view.']);
        }

        $admin->audit($me, 'impersonate.start', $user);

        $request->session()->put('impersonator_id', $me->id);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function stop(Request $request, AdminService $admin): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_id') ?? abort(404);
        $target = $request->user();
        $original = User::find($adminId);

        $request->session()->forget('impersonator_id');

        if ($original === null || ! $original->is_admin) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $admin->audit($original, 'impersonate.stop', $target);

        Auth::login($original);
        $request->session()->regenerate();

        return redirect()->route('admin.users');
    }
}
