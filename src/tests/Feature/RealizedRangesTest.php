<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PositionsService;
use App\Services\RealizedRangesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;

class RealizedRangesTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    private function at(string $local, string $tz = 'America/Denver'): int
    {
        return CarbonImmutable::parse($local, $tz)->getTimestamp();
    }

    public function test_events_sum_to_the_portfolio_realized_total(): void
    {
        $portfolio = app(PositionsService::class)->forUser($this->fixtureUser());

        $this->assertNotEmpty($portfolio->realizedEvents);
        $this->assertSame($portfolio->summary['realized'], array_sum(array_column($portfolio->realizedEvents, 1)));
    }

    public function test_a_user_with_no_trades_has_zero_ranges(): void
    {
        $r = app(RealizedRangesService::class)->build(User::factory()->create(), []);

        $this->assertSame(['day', 'week', 'month', 'quarter', 'all'], array_keys($r));
        $this->assertSame(0, $r['all']['pnl']);
        $this->assertNull($r['all']['prev']);
        $this->assertSame(0, $r['day']['prev']);
    }

    public function test_periods_are_calendar_ranges_in_the_users_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Denver']);
        // Wed 2026-11-04 15:00 Denver; the week starts Mon 2026-11-02, the quarter on 2026-10-01.
        $now = $this->at('2026-11-04 15:00');

        $r = app(RealizedRangesService::class)->build($user, [
            [$this->at('2026-11-04 09:00'), 100],  // today
            [$this->at('2026-11-03 23:30'), 20],   // yesterday, this week
            [$this->at('2026-11-01 12:00'), 5],    // last week, this month
            [$this->at('2026-10-15 12:00'), 7],    // last month, this quarter
            [$this->at('2026-09-30 12:00'), 1000], // last quarter
            [$this->at('2026-11-04 09:00') + 86400 * 30, 99999], // future: counted nowhere but all
        ], $now);

        $this->assertSame([100, 20], [$r['day']['pnl'], $r['day']['prev']]);
        $this->assertSame([120, 5], [$r['week']['pnl'], $r['week']['prev']]);
        $this->assertSame([125, 7], [$r['month']['pnl'], $r['month']['prev']]);
        $this->assertSame([132, 1000], [$r['quarter']['pnl'], $r['quarter']['prev']]);
        $this->assertSame(1000 + 132 + 99999, $r['all']['pnl']);
    }

    public function test_the_week_chart_has_a_bucket_per_day_so_far_and_a_running_total(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Denver']);
        $r = app(RealizedRangesService::class)->build($user, [
            [$this->at('2026-11-02 10:00'), 50],
            [$this->at('2026-11-04 09:00'), -20],
        ], $this->at('2026-11-04 15:00'))['week'];

        $this->assertSame(['Mon 2', 'Tue 3', 'Wed 4'], $r['labels']);
        $this->assertSame([50, 0, -20], $r['pnls']);
        $this->assertSame([50, 50, 30], $r['cum']);
    }

    public function test_every_chart_accounts_for_its_whole_total(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Denver']);
        // Quarter starts on a Thursday: the first bucket is the week of Mon Sep 28.
        $r = app(RealizedRangesService::class)->build($user, [
            [$this->at('2026-10-01 08:00'), 10],
            [$this->at('2026-10-06 08:00'), 20],
            [$this->at('2026-11-04 09:00'), 30],
            [$this->at('2026-11-04 09:00') + 86400 * 30, 40], // future: last all-time bucket
        ], $this->at('2026-11-04 15:00'));

        foreach (['day', 'week', 'month', 'quarter', 'all'] as $key) {
            $this->assertSame($r[$key]['pnl'], array_sum($r[$key]['pnls']), $key);
            $this->assertSame($r[$key]['pnl'], end($r[$key]['cum']), $key);
        }

        $this->assertSame(60, $r['quarter']['pnl']);
        $this->assertSame(10, $r['quarter']['pnls'][0]);
        $this->assertSame(20, $r['quarter']['pnls'][1]);
    }

    public function test_the_all_time_chart_starts_at_the_first_event_month(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $r = app(RealizedRangesService::class)->build($user, [
            [$this->at('2026-08-20 10:00', 'UTC'), 10],
            [$this->at('2026-10-02 10:00', 'UTC'), 5],
        ], $this->at('2026-11-04 15:00', 'UTC'))['all'];

        $this->assertSame(['Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026'], $r['labels']);
        $this->assertSame([10, 0, 5, 0], $r['pnls']);
    }

    public function test_the_dashboard_renders_the_ranges(): void
    {
        $user = $this->fixtureUser(prices: true);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Realized over time')
            ->assertSee('This quarter');
    }
}
