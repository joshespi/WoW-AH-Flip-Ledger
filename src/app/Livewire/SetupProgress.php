<?php

namespace App\Livewire;

use App\Models\Upload;
use Illuminate\View\View;
use Livewire\Component;

/** Polls until the user's first successful upload arrives, then stops. */
class SetupProgress extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        $first = Upload::ownedBy($user)->succeeded()->oldestFirst()->first();
        $failed = $first === null ? Upload::ownedBy($user)->failures()->newest()->first() : null;

        return view('livewire.setup-progress', ['first' => $first, 'failed' => $failed, 'user' => $user]);
    }
}
