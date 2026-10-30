<?php

namespace Tests\Unit;

use App\Services\TsmParseException;
use App\Services\TsmParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;

class TsmParserTest extends TestCase
{
    use Fixtures;

    private function sv(): string
    {
        return $this->fixture('tsm/TradeSkillMaster.lua');
    }

    private function csvLine(string $realm, string $kind, string $body): string
    {
        return '["r@'.$realm.'@internalData@'.$kind.'"] = "'.$body.'",';
    }

    public function test_parses_the_fixture_sections(): void
    {
        $data = (new TsmParser)->parse($this->sv());

        $this->assertCount(607, $data->rows('csvSales'));
        $this->assertCount(60, $data->rows('csvBuys'));
        $this->assertCount(36, $data->rows('csvExpired'));
        $this->assertCount(14, $data->rows('csvCancelled'));
        $this->assertSame([], $data->rows('csvNope'));

        $row = $data->rows('csvSales')[0];
        $this->assertSame('TestRealm', $row['realm']);
        $this->assertSame(['realm', 'itemString', 'stackSize', 'quantity', 'price', 'otherPlayer', 'player', 'time', 'source'], array_keys($row));
    }

    public function test_item_names_resolve_to_base_ids_including_pets_quotes_and_utf8(): void
    {
        $names = (new TsmParser)->parse($this->sv())->names;

        $this->assertSame('Arcane Wyrmfish', $names['i:238371']);
        $this->assertSame("Sin'dorei Swarmer", $names['i:238365']);
        $this->assertSame('Fixture Pet', $names['p:2843']);
        $this->assertSame('The "Quoted" Thing', $names['i:999001']);
        $this->assertSame('Café Crème', $names['i:999002']);
        $this->assertArrayNotHasKey('i:238371::1:6710', $names);
    }

    public function test_inventory_sums_across_locations_and_variant_keys(): void
    {
        $inv = (new TsmParser)->parse($this->sv())->inventory;

        $this->assertEquals($this->golden('ledger')['inventory'], $inv);
        $this->assertSame(1000, $inv['i:236776']);
    }

    public function test_crlf_files_parse_the_same_as_lf(): void
    {
        $lf = (new TsmParser)->parse($this->sv());
        $crlf = (new TsmParser)->parse(str_replace("\n", "\r\n", $this->sv()));

        $this->assertEquals($lf, $crlf);
    }

    public function test_sale_hint_keys_fill_names_the_item_lists_miss(): void
    {
        $sv = "\nTradeSkillMasterDB = {\n[\"Hinted Thing\x01i:555\x01100\"] = 1792282309,\n}\n";

        $this->assertSame(['i:555' => 'Hinted Thing'], (new TsmParser)->parse($sv)->names);
    }

    public function test_csv_rows_zip_to_the_shorter_of_header_and_row(): void
    {
        $sv = "\nTradeSkillMasterDB = {\n".$this->csvLine('R', 'csvSales', 'a,b,c\n1,2\n1,2,3,4\n\n5,6,7')."\n}\n";
        $rows = (new TsmParser)->parse($sv)->rows('csvSales');

        $this->assertSame([
            ['realm' => 'R', 'a' => '1', 'b' => '2'],
            ['realm' => 'R', 'a' => '1', 'b' => '2', 'c' => '3'],
            ['realm' => 'R', 'a' => '5', 'b' => '6', 'c' => '7'],
        ], $rows);
    }

    public function test_non_string_and_unterminated_csv_entries_are_skipped_not_fatal(): void
    {
        $sv = "\nTradeSkillMasterDB = {\n[\"r@internalData@csvSales\"] = 10,\n[\"r@R@internalData@csvBuys\"] = \"a,b\\n1,2\n[\"r@R@internalData@csvSales\"] = \"a\\n9\",\n}\n";
        $data = (new TsmParser)->parse($sv);

        $this->assertSame([], $data->rows('csvBuys'));
        $this->assertSame([['realm' => 'R', 'a' => '9']], $data->rows('csvSales'));
    }

    public function test_a_truncated_file_yields_what_was_read(): void
    {
        $sv = $this->sv();
        $cut = strpos($sv, 'TSMItemInfoDB') + 60;
        $data = (new TsmParser)->parse(substr($sv, 0, $cut));

        $this->assertCount(60, $data->rows('csvBuys'));
        $this->assertCount(607, $data->rows('csvSales'));
    }

    public function test_rejects_files_that_are_not_tsm_data(): void
    {
        $this->expectException(TsmParseException::class);
        (new TsmParser)->parse("print('hello')\n");
    }

    public function test_rejects_oversized_files_before_scanning(): void
    {
        $parser = new TsmParser(maxBytes: 1000);

        $this->expectException(TsmParseException::class);
        $parser->parse("TradeSkillMasterDB = {\n".str_repeat('x', 1000));
    }

    public function test_rejects_too_many_lines(): void
    {
        $parser = new TsmParser(maxLines: 100);

        $this->expectException(TsmParseException::class);
        $parser->parse("\nTradeSkillMasterDB = {\n".str_repeat("\n[\"a\"] = 1,", 200));
    }

    public function test_rejects_too_many_rows(): void
    {
        $parser = new TsmParser(maxRows: 5);
        $sv = "\nTradeSkillMasterDB = {\n".$this->csvLine('R', 'csvSales', 'a\n'.implode('\n', range(1, 6)))."\n}\n";

        $this->expectException(TsmParseException::class);
        $parser->parse($sv);
    }

    public function test_hostile_escape_floods_stay_linear(): void
    {
        $head = "\nTradeSkillMasterDB = {\n";

        $floods = [
            'backslashes' => str_repeat('\\', 4_000_000),
            'escaped quotes' => str_repeat('\\"', 2_000_000),
            'decimal escapes' => str_repeat('\\1', 2_000_000),
            'commas' => str_repeat(',', 4_000_000),
            'quote near-misses' => str_repeat('"\\', 2_000_000),
        ];

        foreach ($floods as $label => $body) {
            $sv = $head.$this->csvLine('R', 'csvSales', 'a,b\n'.$body)."\n}\n";
            $start = microtime(true);

            try {
                (new TsmParser)->parse($sv);
            } catch (TsmParseException) {
                // a clean refusal is fine; hanging is not
            }

            $this->assertLessThan(3.0, microtime(true) - $start, "{$label} flood was not linear");
        }
    }

    public function test_hostile_inventory_and_name_lines_stay_linear(): void
    {
        $sv = "\nTradeSkillMasterDB = {\n[\"s@A - Horde - R@internalData@bagQuantity\"] = {\n"
            .str_repeat('["i:1'.str_repeat('9', 200).'"] = ', 5000)."\n}\n"
            .'["'.str_repeat('N', 3_000_000)."\x01i:1\"] = 1,\n"
            ."TSMItemInfoDB = {\n[\"itemStrings\"] = {\n\"".str_repeat('i:1', 1_000_000)."\",\n},\n}\n";

        $start = microtime(true);
        (new TsmParser)->parse($sv);

        $this->assertLessThan(3.0, microtime(true) - $start);
    }
}
