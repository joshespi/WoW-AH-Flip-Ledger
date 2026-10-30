<?php

namespace App\Console;

use App\Models\User;

/** For commands with a `--user=<email or id>` option. */
trait ResolvesUser
{
    protected function userOption(): ?User
    {
        $who = (string) $this->option('user');
        $user = ctype_digit($who) ? User::find((int) $who) : User::where('email', $who)->first();

        if ($user === null) {
            $this->error('Pass --user=<email or id> of an existing account.');
        }

        return $user;
    }
}
