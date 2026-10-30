<?php

namespace Tests\Feature;

use App\Support\MarketStats;
use Tests\TestCase;

class MarketStatsTest extends TestCase
{
    public function test_derived_numbers_wait_for_enough_history(): void
    {
        $min = MarketStats::minDays();
        $stat = fn (?int $days) => (object) ['days_of_data' => $days, 'recent' => '123', 'typical' => null];

        $this->assertFalse(MarketStats::ready(null));
        $this->assertFalse(MarketStats::ready($stat(null)));
        $this->assertFalse(MarketStats::ready($stat($min - 1)));
        $this->assertTrue(MarketStats::ready($stat($min)));

        $this->assertNull(MarketStats::derived(null, 'recent'));
        $this->assertNull(MarketStats::derived($stat($min - 1), 'recent'), 'still collecting data');
        $this->assertSame(123, MarketStats::derived($stat($min), 'recent'), 'ready: cast to int');
        $this->assertNull(MarketStats::derived($stat($min), 'typical'), 'ready but no value');
    }

    public function test_the_threshold_follows_the_config(): void
    {
        config(['ledger.prices.min_days' => 3]);

        $this->assertSame(3, MarketStats::minDays());
        $this->assertTrue(MarketStats::ready((object) ['days_of_data' => 3]));
    }
}
