<?php

namespace Tests\Unit;

use App\Services\TradesPasteParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TradesPasteParserTest extends TestCase
{
    private function parse(string $text): array
    {
        return (new TradesPasteParser)->parse($text);
    }

    public function test_reads_the_chat_line(): void
    {
        $t = $this->parse('You won an auction for [Practically Pork]x5351 for 2,730g 82s')[0];

        $this->assertSame(['name' => 'Practically Pork', 'id' => null, 'qty' => 5351, 'cost' => 27_308_200, 'line' => 1], $t);
    }

    public function test_quantity_defaults_to_one_and_commas_are_allowed(): void
    {
        $this->assertSame(1, $this->parse('You won an auction for [Thing] for 5g')[0]['qty']);
        $this->assertSame(1234, $this->parse('You won an auction for [Thing]x1,234 for 5g')[0]['qty']);
    }

    public function test_missing_silver_and_copper(): void
    {
        $this->assertSame(50_000, $this->parse('You won an auction for [A]x2 for 5g')[0]['cost']);
        $this->assertSame(5_000, $this->parse('You won an auction for [A]x2 for 50s')[0]['cost']);
        $this->assertSame(1_250_000 + 7, $this->parse('You won an auction for [A]x2 for 125g 7c')[0]['cost']);
        $this->assertSame(27_300_005, $this->parse('You won an auction for [A] x 2 for 2,730g 5c')[0]['cost']);
    }

    public function test_item_links_yield_the_id(): void
    {
        $t = $this->parse('You won an auction for |cff1eff00|Hitem:242639::::::::80:::::|h[Practically Pork]|h|rx5 for 10g')[0];

        $this->assertSame(242639, $t['id']);
        $this->assertSame('Practically Pork', $t['name']);
        $this->assertSame(5, $t['qty']);
    }

    public function test_skips_noise_and_reports_pasted_line_numbers(): void
    {
        $trades = $this->parse("[12:00] hello\nYou won an auction for [A]x1 for 1g\r\n\nYou won an auction for [B]x2 for 2g.\n");

        $this->assertSame([2, 4], array_column($trades, 'line'));
        $this->assertSame(['A', 'B'], array_column($trades, 'name'));
    }

    public function test_unreadable_lines_name_the_line(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line 2');

        $this->parse("ok\nYou won an auction for something odd");
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parse('You won an auction for [A]x0 for 1g');
    }
}
