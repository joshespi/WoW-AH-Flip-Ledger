<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->patch('/settings', array_merge([
            'timezone' => 'UTC', 'region' => 'us',
            'target_margin_pct' => '15', 'stale_minutes' => '30', 'market_share_pct' => '5',
        ], $overrides));
    }

    public function test_new_users_start_with_the_documented_defaults_before_and_after_a_reload(): void
    {
        $user = User::factory()->create();

        foreach ([$user, $user->fresh()] as $u) {
            $this->assertSame(15.0, $u->target_margin_pct);
            $this->assertSame(30, $u->stale_minutes);
            $this->assertSame(5.0, $u->market_share_pct);
        }
    }

    public function test_the_settings_page_shows_the_ledger_fields(): void
    {
        $this->actingAs(User::factory()->create(['target_margin_pct' => 22.5]))->get('/settings')
            ->assertOk()
            ->assertSee('Target margin')
            ->assertSee('value="22.5"', false)
            ->assertSee('Market share')
            ->assertSee('Warn when TSM data is older than');
    }

    public function test_the_ledger_fields_save(): void
    {
        $user = User::factory()->create();

        $this->save($user, ['target_margin_pct' => '22.5', 'stale_minutes' => '90', 'market_share_pct' => '2.5'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(22.5, $user->target_margin_pct);
        $this->assertSame(90, $user->stale_minutes);
        $this->assertSame(2.5, $user->market_share_pct);
    }

    public function test_values_outside_the_sane_ranges_are_refused_and_nothing_changes(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['target_margin_pct' => '-1'], ['target_margin_pct' => '501'], ['target_margin_pct' => 'lots'],
            ['stale_minutes' => '4'], ['stale_minutes' => '1441'], ['stale_minutes' => '1.5'],
            ['market_share_pct' => '0'], ['market_share_pct' => '100.5'],
        ] as $bad) {
            $this->save($user, $bad)->assertSessionHasErrors(array_key_first($bad));
        }

        $user->refresh();
        $this->assertSame(15.0, $user->target_margin_pct);
        $this->assertSame(30, $user->stale_minutes);
        $this->assertSame(5.0, $user->market_share_pct);
    }

    public function test_a_post_without_the_ledger_fields_leaves_them_alone(): void
    {
        $user = User::factory()->create(['target_margin_pct' => 40, 'stale_minutes' => 120, 'market_share_pct' => 8]);

        $this->actingAs($user)->patch('/settings', ['timezone' => 'Europe/Paris', 'region' => 'us'])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Europe/Paris', $user->timezone);
        $this->assertSame(40.0, $user->target_margin_pct);
        $this->assertSame(120, $user->stale_minutes);
        $this->assertSame(8.0, $user->market_share_pct);
    }

    public function test_one_user_cannot_change_anothers_settings(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->save($bob, ['target_margin_pct' => '99']);

        $this->assertSame(15.0, $alice->fresh()->target_margin_pct);
        $this->assertSame(99.0, $bob->fresh()->target_margin_pct);
    }
}
