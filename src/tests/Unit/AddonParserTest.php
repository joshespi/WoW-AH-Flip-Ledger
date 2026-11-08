<?php

namespace Tests\Unit;

use App\Services\AddonParser;
use App\Services\TsmParseException;
use App\Support\Game;
use PHPUnit\Framework\TestCase;
use Tests\Support\AddonFile;

class AddonParserTest extends TestCase
{
    private const T = AddonFile::T0;

    public function test_it_reads_every_kind_of_record(): void
    {
        $data = (new AddonParser)->parse(AddonFile::make([
            'buy|'.self::T.'|Area52|238371|200|8000001',
            'sale|'.(self::T + 10).'|Area52|238371|50|2000000|100000',
            'expired|'.(self::T + 20).'|Area52|238371|120',
            'cancelled|'.(self::T + 30).'|Area52|238371|15',
        ], ['238371:132', '5:1']));

        $this->assertSame('addon', $data->origin);

        $buy = $data->rows('csvBuys')[0];
        $this->assertSame('i:238371', $buy['itemString']);
        $this->assertSame('8000001', $buy['total'], 'the exact total travels with the buy');
        $this->assertSame('40000', $buy['price'], 'unit price is the floor of total / qty');
        $this->assertSame('Auction', $buy['source']);

        $sale = $data->rows('csvSales')[0];
        $this->assertSame('38000', $sale['price'], 'a sale is priced per unit after the AH cut');
        $this->assertSame('50', $sale['quantity']);
        $this->assertSame((string) (self::T + 10), $sale['time']);
        $this->assertSame('Area52', $sale['realm']);

        $this->assertSame('120', $data->rows('csvExpired')[0]['quantity']);
        $this->assertSame('15', $data->rows('csvCancelled')[0]['quantity']);
        $this->assertSame(['i:238371' => 132, 'i:5' => 1], $data->inventory);
        $this->assertSame(self::T + 3600, $data->takenAt);
        $this->assertSame([], $data->names);
    }

    public function test_it_skips_invalid_records_and_ignores_other_sections(): void
    {
        $data = (new AddonParser)->parse(AddonFile::make([
            'sale|'.self::T.'|Area52|238371|50|2000000|100000',
            'sale|'.self::T.'|Area52|238371|50|100|100',          // nothing left after the cut
            'sale|'.self::T.'|Area52|238371|0|2000|0',            // zero quantity
            'sale|'.self::T.'|Area52|238371|50|2000000',          // missing field
            'sale|abc|Area52|238371|50|2000000|1',                // bad time
            'sale|'.self::T.'|Bad"Realm|238371|50|2000000|1',     // bad realm
            'sale|'.self::T.'|Area52|0x10|50|2000000|1',          // bad item
            'sale|'.self::T.'|Area52|238371|50|-5|1',             // negative
            'trade|'.self::T.'|Area52|238371|50|2000000|1',       // unknown kind
            'buy|'.self::T.'|Area52|238371|10|1000',
        ], ['238371:5', 'junk', '1:2:3']));

        $this->assertCount(1, $data->rows('csvSales'));
        $this->assertCount(1, $data->rows('csvBuys'));
        $this->assertSame(['i:238371' => 5], $data->inventory);
    }

    public function test_a_file_without_a_last_time_has_no_snapshot_time(): void
    {
        $this->assertNull((new AddonParser)->parse(AddonFile::make(last: null))->takenAt);
        $this->assertNull((new AddonParser)->parse(AddonFile::make(last: 5))->takenAt, 'an implausible time is ignored');
    }

    public function test_it_detects_its_own_files(): void
    {
        $this->assertTrue(AddonParser::looksLike(AddonFile::make()));
        $this->assertTrue(AddonParser::looksLike("\xEF\xBB\xBF\r\nWowFlipLedgerDB = {\n}"));
        $this->assertFalse(AddonParser::looksLike("TradeSkillMasterDB = {\n}"));
        $this->assertFalse(AddonParser::looksLike(''));
    }

    public function test_it_rejects_other_files_and_oversized_ones(): void
    {
        $this->expectException(TsmParseException::class);
        (new AddonParser)->parse("TradeSkillMasterDB = {\n}");
    }

    public function test_size_cap(): void
    {
        $this->expectException(TsmParseException::class);
        (new AddonParser(maxBytes: 100))->parse(AddonFile::make(array_fill(0, 20, 'buy|'.self::T.'|Area52|1|1|1')));
    }

    public function test_sales_waiting_on_a_name_carry_the_name(): void
    {
        $data = (new AddonParser)->parse(AddonFile::make([], [], unresolved: [
            'sale|'.self::T.'|Whisperwind|Void-Tempered Hide|2|166000|8300',
            'sale|'.self::T.'|Whisperwind|Bad|Name|2|166000|8300',
            'buy|'.self::T.'|Whisperwind|Some Name|2|166000|8300',
            'sale|'.self::T.'|Whisperwind|Void-Tempered Hide|0|166000|8300',
        ]));

        $sales = $data->rows('csvSales');
        $this->assertCount(1, $sales);
        $this->assertSame('Void-Tempered Hide', $sales[0]['itemName']);
        $this->assertSame('78850', $sales[0]['price']);
    }

    public function test_the_older_tab_indented_layout_parses_too(): void
    {
        $data = (new AddonParser)->parse(AddonFile::make(['expired|'.self::T.'|Area52|238371|120'], ['238371:5'], legacy: true));

        $this->assertCount(1, $data->rows('csvExpired'));
        $this->assertSame(['i:238371' => 5], $data->inventory);
    }

    public function test_the_real_client_layout_parses(): void
    {
        // A file as the retail client wrote it: no indentation, no entry comments (AddonFile writes its CRLF).
        $file = '
WowFlipLedgerDB = {
["last"] = '.(self::T + 60).',
["seenOrder"] = {
"sale|Void-Tempered Hide|2|166000#2989693",
},
'
            .'["unresolved"] = {
"sale|'.self::T.'|Whisperwind|Void-Tempered Hide|2|166000|8300",
},
'
            .'["events"] = {
"buy|'.self::T.'|Whisperwind|242639|53|212000",
},
'
            .'["chars"] = {
["55605c9a"] = {
["bags"] = {
[238371] = 50,
},
},
},
}
';

        $data = (new AddonParser)->parse($file);

        $this->assertSame('4000', $data->rows('csvBuys')[0]['price']);
        $this->assertSame('212000', $data->rows('csvBuys')[0]['total']);
        $this->assertSame('Void-Tempered Hide', $data->rows('csvSales')[0]['itemName']);
        $this->assertSame(self::T + 60, $data->takenAt);
    }

    public function test_the_interface_stamp_names_the_game_that_wrote_the_file(): void
    {
        $parse = fn (?int $interface) => (new AddonParser)->parse(AddonFile::make(interface: $interface))->game;

        $this->assertSame(Game::RETAIL, $parse(120105));
        $this->assertSame(Game::FOREVER, $parse(16001));
        $this->assertSame(Game::CLASSIC, $parse(11509));
        $this->assertSame(Game::CLASSIC, $parse(50504));
        $this->assertNull($parse(null), 'files from before 0.2.1 say nothing');
        $this->assertNull($parse(42));
    }

    public function test_unknown_interface_bands_refuse_nothing(): void
    {
        $this->assertSame(Game::FOREVER, Game::fromInterface(16099));
        $this->assertSame(Game::CLASSIC, Game::fromInterface(15999));
        $this->assertSame(Game::CLASSIC, Game::fromInterface(20505));
        // A later Forever patch (1.70 and up) must not be read as Classic.
        $this->assertNull(Game::fromInterface(16100));
        $this->assertNull(Game::fromInterface(17001));
        $this->assertNull(Game::fromInterface(19999));
    }
}
