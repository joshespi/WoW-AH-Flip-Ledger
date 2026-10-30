<?php

namespace Tests\Unit;

use App\Support\Bounds;
use PHPUnit\Framework\TestCase;

class BoundsTest extends TestCase
{
    public function test_cost_limit_message_states_the_real_limit(): void
    {
        $this->assertNull(Bounds::tradeProblem(1, 1, Bounds::MAX_COST, time()));
        $this->assertSame('cost must be between 0 and 100,000,000 gold', Bounds::tradeProblem(1, 1, Bounds::MAX_COST + 1, time()));
    }

    public function test_item_id_is_part_of_the_trade_check(): void
    {
        $this->assertSame('item id is out of range', Bounds::tradeProblem(0, 1, 1, time()));
        $this->assertSame('item id is out of range', Bounds::tradeProblem(Bounds::MAX_ITEM_ID + 1, 1, 1, time()));
    }

    public function test_free_text_drops_control_and_bidi_characters(): void
    {
        $this->assertSame('evil txt.exe', Bounds::text("evil\u{202E}txt.exe", 64));
        $this->assertSame('a b', Bounds::note("  a\t\x01 b\n"));
        $this->assertSame('Café', Bounds::text('Café', 64));
        $this->assertSame('abc', Bounds::text('abcdef', 3));
        $this->assertNull(Bounds::note(" \u{200B}\x02 "));
    }
}
