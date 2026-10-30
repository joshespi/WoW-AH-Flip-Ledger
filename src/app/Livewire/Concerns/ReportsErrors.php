<?php

namespace App\Livewire\Concerns;

use InvalidArgumentException;

/**
 * What every write action of the ledger components does: it goes through attempt(), which is
 * also the one place read-only sessions (the demo, an admin viewing as someone) are refused,
 * because Livewire's update endpoint is exempt from ReadOnlySession. Services refuse bad input
 * with an InvalidArgumentException carrying a message for the user, which becomes an error on
 * the form field instead of a failed request. An action that writes without attempt() is open
 * to read-only sessions, so don't.
 */
trait ReportsErrors
{
    /** Shown in the green banner after a successful action. */
    public ?string $message = null;

    /** Runs $action; false, with its message added as an error on $errorKey, when it was refused. */
    protected function attempt(string $errorKey, callable $action): bool
    {
        $this->message = null;

        try {
            auth()->user()->assertWritable();
            $action();
        } catch (InvalidArgumentException $e) {
            $this->addError($errorKey, $e->getMessage());

            return false;
        }

        return true;
    }
}
