<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/** Header, footer and theme for pages anyone can open (landing, About, Privacy, Terms). */
class PublicLayout extends Component
{
    public function __construct(public ?string $title = null) {}

    public function render(): View
    {
        return view('layouts.public');
    }
}
