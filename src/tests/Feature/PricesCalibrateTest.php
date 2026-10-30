<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsMarketData;
use Tests\Support\TempFiles;
use Tests\TestCase;

class PricesCalibrateTest extends TestCase
{
    use RefreshDatabase, SeedsMarketData, TempFiles;

    private function dump(array $items): string
    {
        return $this->tempFile(json_encode(['downloaded' => 1, 'items' => $items]), 'wfl-cal-');
    }

    private function stat(int $id, int $floor, ?int $typical, int $days, ?int $sold = null): void
    {
        $this->seedStats(
            [$id => ['floor' => $floor, 'recent' => $floor, 'typical' => $typical, 'hist60' => $typical, 'sold' => $sold, 'days' => $days]],
            takenAt: strtotime('2026-10-20 12:00:00 UTC'),
        );
    }

    public function test_it_reports_how_closely_our_numbers_track_tsms(): void
    {
        // Ours are exactly TSM's for four items, and 30% high for a fifth (outside the +-20% band).
        foreach ([[1, 100], [2, 200], [3, 300], [4, 400]] as [$id, $v]) {
            $this->stat($id, $v, $v, 20, $v);
        }
        $this->stat(5, 650, 650, 20, 650);

        $file = $this->dump(array_map(
            fn ($v) => ['floor' => $v, 'recent' => $v, 'sale' => $v, 'hist' => $v, 'spd' => $v],
            [1 => 100, 2 => 200, 3 => 300, 4 => 400, 5 => 500],
        ));

        $this->artisan('prices:calibrate', ['file' => $file])
            ->expectsOutputToContain('5 items in the TSM dump, 5 with our stats')
            ->expectsOutputToContain('floor vs minBuyout')
            ->assertSuccessful();
    }

    public function test_derived_metrics_wait_for_enough_days_of_history(): void
    {
        $this->stat(1, 100, 100, 3);
        $this->stat(2, 200, 200, 3);
        $this->stat(3, 300, 300, 3);
        $file = $this->dump([1 => ['floor' => 100, 'recent' => 100, 'sale' => 100, 'hist' => 100, 'spd' => 5], 2 => ['floor' => 200, 'recent' => 200, 'sale' => 200, 'hist' => 200, 'spd' => 5], 3 => ['floor' => 300, 'recent' => 300, 'sale' => 300, 'hist' => 300, 'spd' => 5]]);

        $this->artisan('prices:calibrate', ['file' => $file])
            ->expectsOutputToContain('typical (30d median) vs regionSale')
            ->assertSuccessful();

        $this->artisan('prices:calibrate', ['file' => $file, '--min-days' => 1])->assertSuccessful();
    }

    public function test_it_explains_when_there_is_nothing_to_compare_yet(): void
    {
        $file = $this->dump([1 => ['floor' => 1, 'recent' => 1, 'sale' => 1, 'hist' => 1, 'spd' => 1]]);

        $this->artisan('prices:calibrate', ['file' => $file])->expectsOutputToContain('No item_stats yet')->assertSuccessful();
    }

    public function test_it_rejects_a_file_that_is_not_a_dump(): void
    {
        $this->artisan('prices:calibrate', ['file' => '/nonexistent.json'])->expectsOutputToContain('is not a dump')->assertFailed();

        $this->artisan('prices:calibrate', ['file' => $this->tempFile('{"hello": 1}', 'wfl-cal-')])->assertFailed();
    }
}
