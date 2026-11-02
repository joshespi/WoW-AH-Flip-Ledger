<?php

namespace Tests\Feature;

use App\Livewire\Scan;
use App\Livewire\Trades;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_freshness_strip_polls_while_visible(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertSee('wire:poll.visible.300s', false);
    }

    public function test_public_pages_are_indexable_and_app_pages_are_not(): void
    {
        $this->get('/about')->assertOk()->assertSee('name="description"', false)->assertDontSee('noindex', false);

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertSee('noindex', false);
    }

    public function test_scan_sort_toggles_and_ignores_unknown_columns(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Scan::class)
            ->call('sortBy', 'floor')->assertSet('sort', 'floor')->assertSet('dir', 'desc')
            ->call('sortBy', 'floor')->assertSet('dir', 'asc')
            ->call('sortBy', 'name')->assertSet('dir', 'asc')
            ->call('sortBy', 'bogus')->assertSet('sort', 'name');
    }

    public function test_changing_the_ranking_clears_the_column_sort(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Scan::class)
            ->call('sortBy', 'floor')
            ->set('mode', 'margin')
            ->assertSet('sort', '')->assertSet('dir', 'desc');
    }

    public function test_filters_are_bound_to_the_url(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->withQueryParams(['minSold' => '20', 'mode' => 'margin'])->test(Scan::class)
            ->assertSet('minSold', '20')->assertSet('mode', 'margin');

        Livewire::actingAs($user)->withQueryParams(['q' => 'pork'])->test(Trades::class)
            ->assertSet('search', 'pork');
    }
}
