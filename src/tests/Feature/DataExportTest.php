<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\DataExportService;
use App\Services\TradesImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LoadsLedgerFixture;
use Tests\TestCase;
use ZipArchive;

class DataExportTest extends TestCase
{
    use LoadsLedgerFixture;
    use RefreshDatabase;

    /** @return array<string, string> file name => contents */
    private function unzip(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $files;
    }

    /** @return list<array<string, string>> */
    private function rows(string $csv): array
    {
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $csv), 'strlen'));
        $head = array_shift($lines);

        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }

    public function test_zip_has_every_file_and_the_users_rows_only(): void
    {
        $user = $this->fixtureUser(attributes: ['timezone' => 'America/Denver']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        Trade::create(['user_id' => $other->id, 'item_id' => 1, 'traded_at' => now(), 'qty' => 1, 'cost_copper' => 1, 'side' => 'buy', 'note' => 'THEIRS']);
        $user->createToken('pc', ['ingest']);

        $files = $this->unzip(app(DataExportService::class)->build($user));

        $this->assertEqualsCanonicalizing(
            ['trades.csv', 'corrections.csv', 'sales.csv', 'expired.csv', 'cancelled.csv', 'buy_suggestions.csv', 'watchlist.csv', 'uploads.csv', 'account.csv', 'README.txt'],
            array_keys($files),
        );
        $this->assertCount(DB::table('trades')->where('user_id', $user->id)->count(), $this->rows($files['trades.csv']));
        $this->assertCount(DB::table('tsm_sales')->where('user_id', $user->id)->count(), $this->rows($files['sales.csv']));
        $this->assertGreaterThan(0, count($this->rows($files['sales.csv'])));

        $all = implode("\n", $files);
        $this->assertStringNotContainsString('THEIRS', $all);
        $this->assertStringNotContainsString('other@example.com', $all);
        $this->assertStringNotContainsString($user->password, $all);
        $this->assertStringNotContainsString('wfl_', $all);

        $account = array_column($this->rows($files['account.csv']), 'value', 'field');
        $this->assertSame($user->email, $account['email']);
        $this->assertSame('America/Denver', $account['timezone']);
        $this->assertArrayNotHasKey('password', $account);

        // Times carry the user's offset.
        $this->assertMatchesRegularExpression('/-0[67]:00$/', $this->rows($files['trades.csv'])[0]['time']);
    }

    public function test_exported_trades_import_into_another_account(): void
    {
        $user = $this->fixtureUser();
        $files = $this->unzip(app(DataExportService::class)->build($user));

        $copy = User::factory()->create(['timezone' => 'UTC']);
        $result = app(TradesImportService::class)->import($copy, $files['trades.csv']);

        $this->assertSame(DB::table('trades')->where('user_id', $user->id)->count(), $result['new']);
        $this->assertEquals(
            DB::table('trades')->where('user_id', $user->id)->sum('cost_copper'),
            DB::table('trades')->where('user_id', $copy->id)->sum('cost_copper'),
        );
    }

    public function test_spreadsheet_formulas_are_neutralized(): void
    {
        $this->seedItem(100, '=HYPERLINK("x")');
        $user = User::factory()->create();
        Trade::create(['user_id' => $user->id, 'item_id' => 100, 'traded_at' => now(), 'qty' => 1, 'cost_copper' => 1, 'side' => 'buy', 'note' => '+cmd']);
        WatchlistItem::create(['user_id' => $user->id, 'item_id' => 100, 'notes' => '@x', 'group_name' => '-g']);

        $files = $this->unzip(app(DataExportService::class)->build($user));

        $trade = $this->rows($files['trades.csv'])[0];
        $this->assertSame("'+cmd", $trade['note']);
        $this->assertSame("'=HYPERLINK(\"x\")", $trade['item_name']);
        $watch = $this->rows($files['watchlist.csv'])[0];
        $this->assertSame(["'-g", "'@x"], [$watch['group'], $watch['notes']]);
    }

    public function test_download_route(): void
    {
        $user = $this->fixtureUser();

        $res = $this->actingAs($user)->get('/settings/export')->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('wow-flip-ledger-export-', $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $res->streamedContent());
        $this->get('/settings')->assertSee(route('settings.export'), false);
    }

    public function test_guests_are_sent_to_login_and_downloads_are_rate_limited(): void
    {
        $this->get('/settings/export')->assertRedirect('/login');

        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->get('/settings/export')->assertOk();
        }

        $this->get('/settings/export')->assertStatus(429);
    }

    public function test_deleting_the_account_removes_everything(): void
    {
        $user = $this->fixtureUser();
        $user->createToken('pc', ['ingest']);
        WatchlistItem::create(['user_id' => $user->id, 'item_id' => self::PORK]);
        $other = $this->fixtureUser();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        foreach (['trades', 'tsm_sales', 'tsm_expired', 'tsm_cancelled', 'tsm_buy_candidates', 'inventory', 'uploads', 'watchlist_items'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), $table);
        }

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertGreaterThan(0, DB::table('trades')->where('user_id', $other->id)->count());
    }
}
