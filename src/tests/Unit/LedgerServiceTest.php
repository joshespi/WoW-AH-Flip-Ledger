<?php

namespace Tests\Unit;

use App\Services\LedgerService;
use App\Services\TradesCsvParser;
use App\Services\TsmData;
use App\Services\TsmParser;
use App\Support\Bounds;
use App\Support\ItemName;
use App\Support\Money;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class LedgerServiceTest extends TestCase
{
    use Fixtures;

    /** @var array<string, array<string, int>> */
    private array $ledger;

    private TsmData $data;

    /** @var list<array{ts: int, item: string, qty: int, cost: int, note: string}> */
    private array $trades;

    protected function setUp(): void
    {
        $this->data = (new TsmParser)->parse($this->fixture('tsm/TradeSkillMaster.lua'));
        $this->trades = (new TradesCsvParser)->parse($this->fixture('tsm/trades.csv'), $this->data->names, new DateTimeZone('America/Denver'));

        $this->ledger = (new LedgerService)->build($this->trades, $this->data->rows('csvSales'));
    }

    public function test_the_database_entry_point_gives_the_same_ledger_as_the_csv_one_even_from_a_generator(): void
    {
        $normalized = (function () {
            foreach ($this->data->rows('csvSales') as $r) {
                if (($r['source'] ?? '') === 'Auction') {
                    yield ['item' => ItemName::baseId($r['itemString']), 'ts' => (int) $r['time'], 'qty' => (int) $r['quantity'], 'unit_price' => (int) $r['price']];
                }
            }
        })();

        $this->assertSame($this->ledger, (new LedgerService)->buildFromSales($this->trades, $normalized));
    }

    private function heldAvg(string $id): float
    {
        return $this->ledger[$id]['cost'] / $this->ledger[$id]['inv'];
    }

    public function test_matches_the_legacy_tool_row_for_row(): void
    {
        $golden = $this->golden('ledger');

        $this->assertEquals($golden['ledger'], $this->ledger);
    }

    public function test_arcane_wyrmfish_realized(): void
    {
        $a = $this->ledger['i:238371'];

        $this->assertSame('7,203g 07s 00c', Money::fmt($a['pnl']));
        $this->assertSame(1060, $a['bq']);
        $this->assertSame('40,891g 25s 00c', Money::fmt($a['bc']));
    }

    public function test_sindorei_swarmer_realized_and_held(): void
    {
        $a = $this->ledger['i:238365'];

        $this->assertSame('-1,488g 16s 20c', Money::fmt($a['pnl']));
        $this->assertSame(424, $a['inv']);
        $this->assertSame('20s 00c', Money::fmt($this->heldAvg('i:238365')));
    }

    public function test_practically_pork_held_avg_and_break_even(): void
    {
        $this->assertSame(15402, $this->ledger['i:242639']['inv']);
        $this->assertSame('50s 79c', Money::fmt($this->heldAvg('i:242639')));
        $this->assertSame('53s 46c', Money::fmt($this->heldAvg('i:242639') / (1 - Money::AH_CUT)));
    }

    public function test_shredded_glimmerline_sold_and_profit(): void
    {
        $a = $this->ledger['i:262797'];

        $this->assertSame(42, $a['sq']);
        $this->assertSame('66g 89s 14c', Money::fmt($a['pnl']));
    }

    public function test_shimmersiren_held(): void
    {
        $this->assertSame(1000, $this->ledger['i:238378']['inv']);
        $this->assertSame('30g 10s 00c', Money::fmt($this->heldAvg('i:238378')));
    }

    public function test_totals_match_the_legacy_summary_line(): void
    {
        $this->assertSame('6,496g 53s 80c', Money::fmt(array_sum(array_column($this->ledger, 'pnl'))));
        $this->assertSame('46,521g 26s 64c', Money::fmt(array_sum(array_column($this->ledger, 'cost'))));
        $this->assertSame('1,128g 73s 30c', Money::fmt(array_sum(array_column($this->ledger, 'orev'))));
    }

    public function test_sells_beyond_held_quantity_become_own_stock(): void
    {
        $trade = ['ts' => 100, 'item' => 'i:1', 'qty' => 10, 'cost' => 1000];
        $sale = ['itemString' => 'i:1', 'quantity' => '15', 'price' => '200', 'time' => '200', 'source' => 'Auction'];

        $a = (new LedgerService)->build([$trade], [$sale])['i:1'];

        $this->assertSame(10, $a['sq']);
        $this->assertSame(2000, $a['sr']);
        $this->assertSame(1000, $a['pnl']);
        $this->assertSame(5, $a['oq']);
        $this->assertSame(1000, $a['orev']);
        $this->assertSame(0, $a['inv']);
        $this->assertSame(0, $a['cost']);
    }

    public function test_an_adjustment_removes_units_at_average_cost_with_no_pnl(): void
    {
        $trades = [
            ['ts' => 100, 'item' => 1, 'qty' => 100, 'cost' => 100_000],
            ['ts' => 300, 'item' => 1, 'qty' => 30, 'cost' => 0, 'correction' => 'adjust'],
            ['ts' => 50, 'item' => 1, 'qty' => 5, 'cost' => 0, 'correction' => 'adjust'],      // before the first buy: ignored
            ['ts' => 300, 'item' => 2, 'qty' => 9, 'cost' => 0, 'correction' => 'adjust'],     // no buys: no position
        ];
        $sales = [['item' => 1, 'ts' => 200, 'qty' => 40, 'unit_price' => 2000]];

        $out = (new LedgerService)->buildFromSales($trades, $sales);

        $this->assertSame([1], array_keys($out));
        $this->assertSame(30, $out[1]['inv']);
        $this->assertSame(30_000, $out[1]['cost']);   // 60 left at 60,000, then 30 removed at 1,000 each
        $this->assertSame(80_000 - 40_000, $out[1]['pnl']);
        $this->assertSame(100, $out[1]['bq']);
    }

    public function test_a_vendor_sale_books_proceeds_minus_average_cost(): void
    {
        $trades = [
            ['ts' => 100, 'item' => 1, 'qty' => 100, 'cost' => 100_000],
            ['ts' => 300, 'item' => 1, 'qty' => 30, 'cost' => 45_000, 'correction' => 'vendor'],   // 1,500 each vs 1,000 cost
            ['ts' => 400, 'item' => 1, 'qty' => 140, 'cost' => 210_000, 'correction' => 'vendor'], // 70 held: only their share of the gold counts
        ];
        $seen = [];

        $out = (new LedgerService)->buildFromSales($trades, [], function (int $ts, int $pnl) use (&$seen): void {
            $seen[] = [$ts, $pnl];
        })[1];

        $this->assertSame(0, $out['inv']);
        $this->assertSame(0, $out['cost']);
        $this->assertSame(15_000 + 35_000, $out['pnl']);
        $this->assertSame([[300, 15_000], [400, 35_000]], $seen);
    }

    public function test_a_write_off_removes_units_at_average_cost_as_a_loss(): void
    {
        $trades = [
            ['ts' => 100, 'item' => 1, 'qty' => 100, 'cost' => 100_000],
            ['ts' => 300, 'item' => 1, 'qty' => 30, 'cost' => 0, 'correction' => 'writeoff'],
            ['ts' => 400, 'item' => 1, 'qty' => 500, 'cost' => 0, 'correction' => 'writeoff'],  // more than held: only what is left
        ];
        $sales = [['item' => 1, 'ts' => 200, 'qty' => 40, 'unit_price' => 2000]];

        $out = (new LedgerService)->buildFromSales($trades, $sales)[1];

        $this->assertSame(0, $out['inv']);
        $this->assertSame(0, $out['cost']);
        $this->assertSame(80_000 - 40_000 - 60_000, $out['pnl']);   // the 60 written off cost 1,000 each
        $this->assertSame(40, $out['sq']);
        $this->assertSame(0, $out['oq']);
    }

    public function test_sales_before_the_first_buy_vendor_sales_and_junk_rows_are_ignored(): void
    {
        $trade = ['ts' => 100, 'item' => 'i:1', 'qty' => 10, 'cost' => 1000];
        $sales = [
            ['itemString' => 'i:1', 'quantity' => '5', 'price' => '200', 'time' => '99', 'source' => 'Auction'],
            ['itemString' => 'i:1', 'quantity' => '5', 'price' => '200', 'time' => '150', 'source' => 'Vendor'],
            ['itemString' => 'i:1', 'quantity' => '0', 'price' => '200', 'time' => '150', 'source' => 'Auction'],
            ['itemString' => 'i:1', 'quantity' => 'abc', 'price' => '200', 'time' => '150', 'source' => 'Auction'],
            ['itemString' => 'i:1', 'quantity' => '5', 'price' => '-5', 'time' => '150', 'source' => 'Auction'],
            ['itemString' => 'i:2', 'quantity' => '5', 'price' => '200', 'time' => '150', 'source' => 'Auction'],
            ['quantity' => '5', 'price' => '200', 'time' => '150', 'source' => 'Auction'],
        ];

        $out = (new LedgerService)->build([$trade], $sales);

        $this->assertSame(['i:1'], array_keys($out));
        $this->assertSame(0, $out['i:1']['sq']);
        $this->assertSame(10, $out['i:1']['inv']);
    }

    public function test_buys_sort_before_sells_at_the_same_timestamp(): void
    {
        $trade = ['ts' => 100, 'item' => 'i:1', 'qty' => 10, 'cost' => 1000];
        $sale = ['itemString' => 'i:1', 'quantity' => '4', 'price' => '300', 'time' => '100', 'source' => 'Auction'];

        $a = (new LedgerService)->build([$trade], [$sale])['i:1'];

        $this->assertSame(4, $a['sq']);
        $this->assertSame(0, $a['oq']);
        $this->assertSame(800, $a['pnl']);
    }

    public function test_bonus_id_sale_strings_match_the_base_item(): void
    {
        $trade = ['ts' => 100, 'item' => 'i:1', 'qty' => 10, 'cost' => 1000];
        $sale = ['itemString' => 'i:1::1:6710', 'quantity' => '2', 'price' => '100', 'time' => '200', 'source' => 'Auction'];

        $this->assertSame(2, (new LedgerService)->build([$trade], [$sale])['i:1']['sq']);
    }

    public function test_the_largest_allowed_lot_does_not_overflow(): void
    {
        // cost x qty here is 1e19, past PHP_INT_MAX.
        $a = (new LedgerService)->buildFromSales(
            [['ts' => 1, 'item' => 1, 'qty' => Bounds::MAX_QTY, 'cost' => Bounds::MAX_COST]],
            [['item' => 1, 'ts' => 2, 'qty' => Bounds::MAX_QTY - 1, 'unit_price' => 100_000]],
        )[1];

        $this->assertSame(1, $a['inv']);
        $this->assertSame(100_000, $a['cost']);
        $this->assertSame(0, $a['pnl']);
    }
}
