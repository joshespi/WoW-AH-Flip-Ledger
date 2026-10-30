<?php

namespace Tests\Feature;

use App\Livewire\Calculator;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_price_is_cost_over_the_ah_cut(): void
    {
        $this->assertEqualsWithDelta(100 / 0.95, Money::sellPrice(100), 1e-9);
        $this->assertEqualsWithDelta(100 * 1.15 / 0.95, Money::sellPrice(100, 15), 1e-9);
    }

    public function test_page_requires_login_and_renders(): void
    {
        $this->get('/calculator')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/calculator')->assertOk()->assertSee('Buy calculator');
    }

    public function test_rows_for_a_cost(): void
    {
        $cost = Money::parseGold('5g25s55c');

        Livewire::actingAs(User::factory()->create(['target_margin_pct' => 15]))->test(Calculator::class)
            ->set('cost', '5g25s55c')
            ->assertViewHas('rows', function (array $rows) use ($cost) {
                $this->assertSame([0.0, 5.0, 10.0, 15.0, 25.0], array_map(fn ($r) => (float) $r['margin'], $rows));
                $this->assertSame((int) ceil($cost / 0.95), $rows[0]['sell']);
                $this->assertSame((int) ceil($cost * 1.15 / 0.95), $rows[3]['sell']);
                $this->assertTrue($rows[3]['target']);
                $this->assertFalse($rows[0]['target']);
                // Break-even lists at the cost after rounding up: profit is a copper or so, never a loss.
                $this->assertGreaterThanOrEqual(0, $rows[0]['profit']);
                $this->assertGreaterThan(0, $rows[3]['profit']);
                $this->assertNull($rows[3]['stack']);

                return true;
            });
    }

    public function test_custom_target_is_added_in_order(): void
    {
        Livewire::actingAs(User::factory()->create(['target_margin_pct' => 18]))->test(Calculator::class)
            ->set('cost', '100')
            ->assertViewHas('rows', fn (array $rows) => array_map(fn ($r) => (float) $r['margin'], $rows) === [0.0, 5.0, 10.0, 15.0, 18.0, 25.0]);
    }

    public function test_quantity_adds_totals(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Calculator::class)
            ->set('cost', '10s')
            ->set('quantity', '200')
            ->assertViewHas('outlay', 1000 * 200)
            ->assertViewHas('rows', fn (array $rows) => $rows[3]['stack'] === $rows[3]['profit'] * 200);
    }

    public function test_bad_or_out_of_range_input_shows_an_error_and_no_rows(): void
    {
        $test = Livewire::actingAs(User::factory()->create())->test(Calculator::class);

        foreach (['lots', '-5g', '0', '99999999999g'] as $bad) {
            $test->set('cost', $bad)
                ->assertViewHas('rows', [])
                ->assertViewHas('error', fn ($e) => $e !== null);
        }

        $test->set('cost', '')->assertViewHas('rows', [])->assertViewHas('error', null);
    }
}
