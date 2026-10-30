<?php

namespace Tests\Unit;

use App\Services\FallingKnife;
use PHPUnit\Framework\TestCase;

class FallingKnifeTest extends TestCase
{
    public function test_cheap_and_still_falling_is_a_knife(): void
    {
        $this->assertTrue(FallingKnife::is(70, 85, 100, 0.8));
    }

    public function test_not_cheap_enough(): void
    {
        $this->assertFalse(FallingKnife::is(90, 95, 100, 0.8));
    }

    public function test_cheap_but_recovering_is_not_a_knife(): void
    {
        $this->assertFalse(FallingKnife::is(70, 70, 100, 0.8));  // floor not under recent
        $this->assertFalse(FallingKnife::is(70, 110, 100, 0.8)); // recent above typical
    }

    public function test_boundary_is_inclusive(): void
    {
        $this->assertTrue(FallingKnife::is(80, 90, 100, 0.8));
        $this->assertFalse(FallingKnife::is(81, 90, 100, 0.8));
    }

    public function test_never_without_ready_stats_or_numbers(): void
    {
        $this->assertFalse(FallingKnife::is(null, 85, 100, 0.8));
        $this->assertFalse(FallingKnife::is(70, null, 100, 0.8));
        $this->assertFalse(FallingKnife::is(70, 85, null, 0.8));
        $this->assertFalse(FallingKnife::is(0, 85, 100, 0.8));
    }
}
