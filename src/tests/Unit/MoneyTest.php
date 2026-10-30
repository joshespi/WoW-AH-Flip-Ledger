<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class MoneyTest extends TestCase
{
    use Fixtures;

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function fmtCases(): iterable
    {
        $golden = json_decode(file_get_contents(__DIR__.'/../fixtures/golden/money.golden.json'), true);

        foreach ($golden['fmt'] as [$copper, $expected]) {
            yield "{$copper} -> {$expected}" => [$copper, $expected];
        }
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function parseCases(): iterable
    {
        $golden = json_decode(file_get_contents(__DIR__.'/../fixtures/golden/money.golden.json'), true);

        foreach ($golden['parse_gold'] as $text => $expected) {
            yield "'{$text}'" => [(string) $text, $expected];
        }
    }

    #[DataProvider('fmtCases')]
    public function test_fmt_matches_legacy(int|float $copper, string $expected): void
    {
        $this->assertSame($expected, Money::fmt($copper));
    }

    #[DataProvider('parseCases')]
    public function test_parse_gold_matches_legacy(string $text, ?int $expected): void
    {
        if ($expected === null) {
            $this->expectException(InvalidArgumentException::class);
        }

        $this->assertSame($expected, Money::parseGold($text));
    }

    public function test_fmt_then_parse_round_trips_integers_including_negatives_and_sub_gold(): void
    {
        foreach ([0, 1, 5, 99, 100, 9999, 10000, 12345, 123456789, 40891250000, -1, -99, -100, -9999, -10000, -123456789] as $copper) {
            $this->assertSame($copper, Money::parseGold(Money::fmt($copper)), "round trip of {$copper}");
        }
    }

    public function test_display_forms(): void
    {
        $this->assertSame('1,234g 56s 78c', Money::fmt(12_345_678));
        $this->assertSame('5s 03c', Money::fmt(503));
        $this->assertSame('0s 00c', Money::fmt(0));
        $this->assertSame('-0s 01c', Money::fmt(-1));
    }

    public function test_parts_split_for_colored_display(): void
    {
        $this->assertSame(['sign' => '-', 'g' => 12, 's' => 34, 'c' => 56], Money::parts(-123_456));
        $this->assertSame(['sign' => '', 'g' => 0, 's' => 0, 'c' => 0], Money::parts(0));
    }

    public function test_rounding_is_half_to_even_like_python(): void
    {
        $this->assertSame(0, Money::round(0.5));
        $this->assertSame(2, Money::round(1.5));
        $this->assertSame(2, Money::round(2.5));
        $this->assertSame(0, Money::round(-0.5));
    }

    public function test_units_and_fractions_in_user_typed_amounts(): void
    {
        $this->assertSame(27_308_200, Money::parseGold('2730.82'));
        $this->assertSame(27_308_200, Money::parseGold('2,730g 82s'));
        $this->assertSame(27_308_200, Money::parseGold('2,730g 82s 00c'));
        $this->assertSame(4_927_680, Money::parseGold('492.768'));
        $this->assertSame(50_790, Money::parseGold('5g 7s 90c'));
    }

    public function test_rejects_garbage_and_oversized_input(): void
    {
        // Digit runs past an int (13+ digits) were 0 copper or a TypeError.
        foreach (['', '   ', 'abc', 'g', '-', '5x', '1e3', "5g\n6s7", str_repeat('9', 40), '9999999999999', '99999999999999999999g', '1g 99999999999999999999c'] as $bad) {
            try {
                Money::parseGold($bad);
                $this->fail("accepted '{$bad}'");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Money::parseGold('5g'.str_repeat(' ', 5000).'6s');
    }
}
