<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

/** The header freshness strip, re-rendered every few minutes while the tab is visible. */
class FreshnessStrip extends Component
{
    public function render(): View
    {
        return view('livewire.freshness-strip');
    }
}
