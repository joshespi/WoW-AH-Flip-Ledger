<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a verified local login. Never runs in production, and reruns update the same row.
     * Credentials come from SEED_USER_EMAIL / SEED_USER_PASSWORD in .env.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping user seed in production.');

            return;
        }

        User::updateOrCreate(
            ['email' => env('SEED_USER_EMAIL', 'test@example.com')],
            [
                'name' => 'Test User',
                'password' => env('SEED_USER_PASSWORD', 'password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
