<?php

namespace App\View\Components;

use App\Models\User;
use App\Services\Freshness;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FreshnessBar extends Component
{
    public ?Freshness $freshness = null;

    private ?User $user;

    public function __construct()
    {
        $this->user = auth()->user();
        $this->freshness = $this->user === null ? null : Freshness::for($this->user);
    }

    /** Nothing to show a guest (an error page, a future public page that uses the app layout). */
    public function shouldRender(): bool
    {
        return $this->freshness !== null;
    }

    public function render(): View
    {
        return view('components.freshness-bar');
    }

    /** Time of day, with the date once it is not today. */
    public function when(int $ts): string
    {
        $t = $this->user->local($ts);

        return $t->isToday() ? $t->format('H:i') : $t->format('M j H:i');
    }
}
