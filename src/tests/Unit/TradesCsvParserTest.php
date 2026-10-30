<?php

namespace Tests\Unit;

use App\Services\TradesCsvParser;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TradesCsvParserTest extends TestCase
{
    private const NAMES = [
        'i:1' => 'Practically Pork',
        'i:2' => 'Void-Tempered Leather',
        'i:3' => 'Void-Tempered Leather',
        'p:9' => 'Pet',
    ];

    private function parse(string $csv, string $tz = 'UTC'): array
    {
        return (new TradesCsvParser)->parse($csv, self::NAMES, new DateTimeZone($tz));
    }

    public function test_reads_ids_names_and_gold_text(): void
    {
        $trades = $this->parse("time,item,qty,cost,note\n2026-10-12T10:51-06:00,i:1,625,\"2,730g 82s\",x\n2026-10-12T10:51-06:00,practically pork,5,1.5,y\n");

        $this->assertSame(['ts' => 1_791_823_860, 'item' => 'i:1', 'qty' => 625, 'cost' => 27_308_200, 'note' => 'x', 'line' => 2], $trades[0]);
        $this->assertSame(['item' => 'i:1', 'qty' => 5, 'cost' => 15_000, 'note' => 'y'], ['item' => $trades[1]['item'], 'qty' => $trades[1]['qty'], 'cost' => $trades[1]['cost'], 'note' => $trades[1]['note']]);
    }

    public function test_times_without_an_offset_use_the_given_timezone(): void
    {
        $utc = $this->parse("time,item,qty,cost\n2026-10-12T10:51,i:1,1,1\n", 'UTC')[0]['ts'];
        $denver = $this->parse("time,item,qty,cost\n2026-10-12T10:51,i:1,1,1\n", 'America/Denver')[0]['ts'];

        $this->assertSame(6 * 3600, $denver - $utc);
    }

    public function test_strips_bonus_ids_from_explicit_item_strings(): void
    {
        $this->assertSame('i:1', $this->parse("time,item,qty,cost\n2026-10-12T10:51Z,i:1::1:6710,1,1\n")[0]['item']);
    }

    public function test_ambiguous_and_unknown_names_fail_with_the_line_number(): void
    {
        foreach (['Void-Tempered Leather', 'No Such Item'] as $name) {
            try {
                $this->parse("time,item,qty,cost\n2026-10-12T10:51Z,i:1,1,1\n2026-10-12T10:51Z,{$name},1,1\n");
                $this->fail('accepted '.$name);
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('line 3:', $e->getMessage());
            }
        }
    }

    public function test_bad_qty_time_and_cost_are_rejected(): void
    {
        foreach (['x,1,1', '2026-10-12T10:51Z,i:1,-5,1', '2026-10-12T10:51Z,i:1,1,lots', 'not a time,i:1,1,1'] as $row) {
            try {
                $this->parse("time,item,qty,cost\n{$row}\n");
                $this->fail('accepted '.$row);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_header_is_matched_case_and_space_insensitively_in_any_order_and_note_is_optional(): void
    {
        $trades = $this->parse(" Cost ,QTY, Item ,TIME\n5,3,i:1,2026-10-12T10:51Z\n");

        $this->assertSame(['item' => 'i:1', 'qty' => 3, 'cost' => 50_000, 'note' => ''], array_intersect_key($trades[0], array_flip(['item', 'qty', 'cost', 'note'])));
    }

    public function test_a_missing_or_absent_header_is_refused_by_name_on_line_one(): void
    {
        foreach (["time,item,qty\n2026-10-12T10:51Z,i:1,1\n", "2026-10-12T10:51Z,i:1,1,5\n", "\n2026-10-12T10:51Z,i:1,1,5\n", ''] as $csv) {
            try {
                $this->parse($csv);
                $this->fail('accepted '.json_encode($csv));
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('line 1:', $e->getMessage());
                $this->assertStringContainsString('time,item,qty,cost,note', $e->getMessage());
            }
        }

        try {
            $this->parse("time,item,qty\n");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('missing: cost', $e->getMessage());
        }
    }

    public function test_a_repeated_header_column_is_refused_on_line_one(): void
    {
        try {
            $this->parse("time,item,qty,cost,Cost\n2026-10-12T10:51Z,i:1,1,5,9\n");
            $this->fail('accepted a repeated column');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('line 1:', $e->getMessage());
            $this->assertStringContainsString('repeated: cost', $e->getMessage());
        }
    }

    public function test_only_iso_times_are_accepted_not_phps_relative_words(): void
    {
        foreach (['now', 'yesterday', '+1 week', 'next friday', 'tomorrow noon', '12/10/2026', '10 Oct 2026'] as $bad) {
            try {
                $this->parse("time,item,qty,cost\n{$bad},i:1,1,1\n");
                $this->fail("accepted '{$bad}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('bad time', $e->getMessage());
            }
        }

        foreach (['2026-10-12', '2026-10-12 10:51', '2026-10-12T10:51:30', '2026-10-12T10:51:30.250Z', '2026-10-12T10:51+0200', '2026-10-12T10:51+02'] as $good) {
            $this->assertCount(1, $this->parse("time,item,qty,cost\n{$good},i:1,1,1\n"), $good);
        }
    }

    public function test_blank_lines_are_skipped(): void
    {
        $this->assertCount(1, $this->parse("time,item,qty,cost\n\n2026-10-12T10:51Z,i:1,1,1\n\n"));
    }
}
