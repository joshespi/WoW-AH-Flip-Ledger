<?php

namespace Tests\Feature;

use App\Services\Blizzard\BlizzardException;
use App\Services\Blizzard\CommoditiesParser;
use Tests\Support\FakesBlizzard;
use Tests\TestCase;

class CommoditiesParserTest extends TestCase
{
    use FakesBlizzard;

    private function aggregate(string $json): array
    {
        return (new CommoditiesParser)->aggregate($this->tempFile($json));
    }

    public function test_it_aggregates_floor_quantity_and_auction_count_per_item(): void
    {
        $items = $this->aggregate($this->commoditiesBody());

        $this->assertSame([
            238371 => ['floor' => 384000, 'quantity' => 3500, 'auctions' => 3],
            242639 => ['floor' => 5100, 'quantity' => 15200, 'auctions' => 2],
            238365 => ['floor' => 1999, 'quantity' => 851, 'auctions' => 4],
            238511 => ['floor' => 62000, 'quantity' => 10, 'auctions' => 1],
        ], $items);
    }

    public function test_rows_it_cannot_use_are_skipped_not_guessed_at(): void
    {
        $items = $this->aggregate(json_encode(['auctions' => [
            ['item' => ['id' => 1], 'quantity' => 5, 'unit_price' => 100],
            ['item' => ['id' => 2], 'quantity' => 1, 'buyout' => 5000, 'bid' => 4000], // an equipment-style auction
            ['item' => ['id' => 3], 'quantity' => 0, 'unit_price' => 100],
            ['item' => ['id' => 4], 'quantity' => 5, 'unit_price' => 0],
            ['item' => ['id' => 5], 'quantity' => -1, 'unit_price' => 100],
            ['item' => ['id' => 6], 'quantity' => '5', 'unit_price' => 100],
            ['item' => ['id' => 7], 'quantity' => 5, 'unit_price' => 1.5],
            ['item' => ['id' => 'x'], 'quantity' => 5, 'unit_price' => 100],
            ['item' => ['id' => 0], 'quantity' => 5, 'unit_price' => 100],
            ['quantity' => 5, 'unit_price' => 100],
            'not even an object',
        ]]));

        $this->assertSame([1 => ['floor' => 100, 'quantity' => 5, 'auctions' => 1]], $items);
    }

    public function test_bad_responses_throw_instead_of_producing_a_partial_snapshot(): void
    {
        $good = $this->commoditiesBody();

        $bad = [
            'truncated json' => substr($good, 0, (int) (strlen($good) * 0.6)),
            'html error page' => '<html><body>502 Bad Gateway</body></html>',
            'empty file' => '',
            'no auctions key' => '{"_links":{}}',
            'empty auctions' => '{"auctions":[]}',
            'only unusable rows' => '{"auctions":[{"item":{"id":1},"quantity":1,"buyout":9}]}',
            'wrong shape' => '{"auctions":{"a":1}}',
            'not json' => 'TradeSkillMasterDB = {}',
        ];

        foreach ($bad as $label => $body) {
            try {
                $this->aggregate($body);
                $this->fail("{$label} was accepted");
            } catch (BlizzardException $e) {
                $this->assertStringContainsString('Blizzard returned', $e->getMessage(), $label);
            }
        }
    }

    public function test_a_large_response_streams_in_flat_memory(): void
    {
        $auctions = 250_000;
        $path = $this->bigCommoditiesFile($auctions, 2_000);
        $fileMb = filesize($path) / 1048576;
        $this->assertGreaterThan(20, $fileMb, 'the test file should be big enough to matter');

        gc_collect_cycles();
        $before = memory_get_usage(true);
        $start = microtime(true);

        $items = (new CommoditiesParser)->aggregate($path);

        $elapsed = microtime(true) - $start;
        $grew = (memory_get_peak_usage(true) - $before) / 1048576;

        $this->assertCount(2_000, $items);
        $this->assertSame($auctions, array_sum(array_column($items, 'auctions')));
        // Loading it whole would cost several times the file size; streaming costs ~ the aggregates.
        $this->assertLessThan(25, $grew, sprintf('memory grew %.1f MB parsing a %.1f MB file', $grew, $fileMb));
        fwrite(STDERR, sprintf("\n[parser] %d auctions, %.1f MB file, %.1f s, +%.1f MB peak\n", $auctions, $fileMb, $elapsed, $grew));
    }
}
