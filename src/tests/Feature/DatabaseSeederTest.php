<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_one_verified_user_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());

        $user = User::firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_does_not_seed_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }
}
