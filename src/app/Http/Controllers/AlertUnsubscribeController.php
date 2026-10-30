<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

/**
 * Turns every price alert off for the account in the signed link, without signing in. GET shows
 * a confirmation (so a link scanner opening the URL changes nothing); POST does it, and is also
 * what a mail client's one-click unsubscribe sends (CSRF-exempt, protected by the signature).
 */
class AlertUnsubscribeController extends Controller
{
    public function show(User $user): View
    {
        return view('alerts.unsubscribe', ['done' => ! $user->alerts_enabled]);
    }

    public function store(User $user): View
    {
        $user->update(['alerts_enabled' => false]);

        return view('alerts.unsubscribe', ['done' => true]);
    }
}
