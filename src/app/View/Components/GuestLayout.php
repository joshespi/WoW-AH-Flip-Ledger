<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /** @param  string|null  $title  page name for the browser tab ("Log in · WoW Flip Ledger") */
    public function __construct(public ?string $title = null) {}

    public function render(): View
    {
        return view('layouts.guest');
    }
}
