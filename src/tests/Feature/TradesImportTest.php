<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TradesImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\Support\Fixtures;
use Tests\Support\SeedsMarketData;
use Tests\TestCase;

class TradesImportTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;
    use SeedsMarketData;

    private const HEAD = "time,item,qty,cost,note\n";

    private function import(User $user, string $body): array
    {
        return app(TradesImportService::class)->import($user, self::HEAD.$body);
    }

    private function names(): void
    {
        $this->seedItem(100, 'Practically Pork');
        $this->seedItem(200, 'Void-Tempered Leather');
        $this->seedItem(201, 'Void-Tempered Leather');

    }

    public function test_imports_the_fixture_trades_and_reimporting_adds_nothing(): void
    {
        $user = User::factory()->create();
        $csv = $this->fixture('tsm/trades.csv');

        $first = app(TradesImportService::class)->import($user, $csv);
        $second = app(TradesImportService::class)->import($user, $csv);

        $this->assertSame(['new' => 38, 'duplicates' => 0], $first);
        $this->assertSame(['new' => 0, 'duplicates' => 38], $second);
        $this->assertSame(38, $user->trades()->count());
    }

    public function test_stores_utc_times_lot_totals_and_notes(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Denver']);

        $this->import($user, "2026-10-12T10:51-06:00,i:238371,625,\"2,730g 82s\",first\n2026-10-12T10:51,i:238371,5,1.5,\n");

        $rows = $user->trades()->orderBy('id')->get();

        $this->assertSame('2026-10-12 16:51:00', $rows[0]->traded_at->format('Y-m-d H:i:s'));
        $this->assertSame(27_308_200, $rows[0]->cost_copper);
        $this->assertSame('first', $rows[0]->note);
        $this->assertSame('csv', $rows[0]->source);
        $this->assertSame('buy', $rows[0]->side);
        // no offset: read in the user's own timezone (Denver is UTC-6 in October)
        $this->assertSame('2026-10-12 16:51:00', $rows[1]->traded_at->format('Y-m-d H:i:s'));
        $this->assertNull($rows[1]->note);
    }

    public function test_identical_rows_in_one_file_are_both_kept_but_not_duplicated_on_rerun(): void
    {
        $user = User::factory()->create();
        $row = "2026-10-12T10:51Z,i:238371,100,5,\n";

        $this->assertSame(['new' => 2, 'duplicates' => 0], $this->import($user, $row.$row));
        $this->assertSame(['new' => 0, 'duplicates' => 2], $this->import($user, $row.$row));
        $this->assertSame(2, $user->trades()->count());
    }

    public function test_item_names_resolve_through_the_catalog(): void
    {
        $this->names();
        $user = User::factory()->create();

        $this->import($user, "2026-10-12T10:51Z,practically pork,10,1,\n");

        $this->assertSame(100, $user->trades()->first()->item_id);
    }

    public function test_ambiguous_and_unknown_names_are_rejected_and_nothing_is_saved(): void
    {
        $this->names();
        $user = User::factory()->create();

        foreach (['Void-Tempered Leather', 'No Such Thing'] as $name) {
            try {
                $this->import($user, "2026-10-12T10:51Z,i:238371,1,1,\n2026-10-12T10:51Z,{$name},1,1,\n");
                $this->fail("accepted {$name}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('line 3', $e->getMessage());
            }
        }

        $this->assertSame(0, $user->trades()->count());
    }

    public function test_bad_values_are_rejected_before_anything_is_written(): void
    {
        $user = User::factory()->create();
        $good = "2026-10-12T10:51Z,i:238371,1,1,\n";

        $bad = [
            'pet' => ['p:2843', '2026-10-12T10:51Z,p:2843,1,1,'],
            'zero qty' => ['quantity', '2026-10-12T10:51Z,i:1,0,1,'],
            'huge qty' => ['quantity', '2026-10-12T10:51Z,i:1,99999999999,1,'],
            'huge cost' => ['cost', '2026-10-12T10:51Z,i:1,1,999999999g,'],
            'negative cost' => ['cost', '2026-10-12T10:51Z,i:1,1,-5,'],
            'ancient' => ['time', '1999-01-01T00:00Z,i:1,1,1,'],
            'future' => ['time', '2999-01-01T00:00Z,i:1,1,1,'],
        ];

        foreach ($bad as $label => [$needle, $row]) {
            try {
                $this->import($user, $good.$row."\n");
                $this->fail("accepted {$label}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $label);
                // Parser and service both report the CSV line (header is line 1).
                $this->assertStringContainsString('line 3', $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, $user->trades()->count(), 'a bad row must leave the good ones unsaved too');
    }

    public function test_size_and_row_caps(): void
    {
        $user = User::factory()->create();

        try {
            app(TradesImportService::class)->import($user, str_repeat('x', TradesImportService::MAX_BYTES + 1));
            $this->fail('accepted an oversized file');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }

        $rows = '';
        for ($i = 0; $i <= TradesImportService::MAX_ROWS; $i++) {
            $rows .= "2026-10-12T10:51Z,i:1,1,{$i},\n";
        }

        $this->expectException(InvalidArgumentException::class);
        $this->import($user, $rows);
    }

    public function test_a_utf8_bom_from_excel_does_not_break_the_header(): void
    {
        $user = User::factory()->create();

        $result = app(TradesImportService::class)->import($user, "\xEF\xBB\xBF".self::HEAD."2026-10-12T10:51Z,i:238371,1,1,\n");

        $this->assertSame(1, $result['new']);
    }

    public function test_each_user_gets_their_own_copy_and_never_sees_anothers(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $csv = $this->fixture('tsm/trades.csv');

        app(TradesImportService::class)->import($alice, $csv);

        $this->assertSame(['new' => 38, 'duplicates' => 0], app(TradesImportService::class)->import($bob, $csv));
        $this->assertSame(38, $alice->trades()->count());
        $this->assertSame(38, $bob->trades()->count());
    }

    public function test_a_file_listing_a_lot_twice_adds_one_more_than_a_file_listing_it_once(): void
    {
        $user = User::factory()->create();
        $row = "2026-10-12T10:51Z,i:238371,100,5,\n";

        $this->assertSame(['new' => 1, 'duplicates' => 0], $this->import($user, $row));
        $this->assertSame(['new' => 1, 'duplicates' => 1], $this->import($user, $row.$row));
        $this->assertSame(['new' => 0, 'duplicates' => 2], $this->import($user, $row.$row));
        $this->assertSame(2, $user->trades()->count());
    }

    public function test_the_note_is_not_part_of_a_rows_identity(): void
    {
        $user = User::factory()->create();

        $this->import($user, "2026-10-12T10:51Z,i:238371,100,5,first note\n");

        $this->assertSame(['new' => 0, 'duplicates' => 1], $this->import($user, "2026-10-12T10:51Z,i:238371,100,5,edited note\n"));
    }

    public function test_trade_imports_and_tsm_uploads_have_separate_rate_limits(): void
    {
        config(['ledger.ingest.trades_import_per_user_per_hour' => 1]);
        $user = User::factory()->create();
        $post = fn () => $this->actingAs($user)->post('/trades/import', ['csv_text' => self::HEAD."2026-10-12T10:51Z,i:238371,1,1,\n"]);

        $post()->assertRedirect('/trades/import');
        $post()->assertStatus(429);

        // An upload is a different bucket: it gets as far as validation, not a 429.
        $this->actingAs($user)->post('/uploads', [])->assertSessionHasErrors('file');
    }

    public function test_the_template_downloads_as_csv_and_imports_cleanly(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->get('/trades/import/template')->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('trades-template.csv', $res->headers->get('Content-Disposition'));

        $this->assertSame(['new' => 2, 'duplicates' => 0], app(TradesImportService::class)->import($user, $res->streamedContent()));
        $this->actingAs($user)->get('/trades/import')->assertSee(route('trades.import.template'), false);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/trades/import/template')->assertRedirect('/login');
        $this->get('/trades/import')->assertRedirect('/login');
        $this->post('/trades/import', [])->assertRedirect('/login');
    }

    public function test_the_page_explains_the_format(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'America/Denver']))
            ->get('/trades/import')
            ->assertOk()
            ->assertSee('time,item,qty,cost,note')
            ->assertSee('America/Denver');
    }

    public function test_uploading_a_file_imports_it_and_reports_the_counts(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('trades.csv', $this->fixture('tsm/trades.csv'));

        $this->actingAs($user)->post('/trades/import', ['csv' => $file])
            ->assertRedirect('/trades/import')
            ->assertSessionHas('imported', ['new' => 38, 'duplicates' => 0]);

        $this->actingAs($user)->get('/trades/import')->assertSee('38 buys added');
        $this->assertSame(38, $user->trades()->count());
    }

    public function test_pasted_text_imports_too(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/trades/import', ['csv_text' => self::HEAD."2026-10-12T10:51Z,i:238371,1,1,\n"])
            ->assertSessionHas('imported', ['new' => 1, 'duplicates' => 0]);
    }

    public function test_nothing_submitted_and_bad_files_show_an_error_and_keep_the_pasted_text(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/trades/import', [])->assertSessionHasErrors('csv');

        $bad = self::HEAD."2026-10-12T10:51Z,i:1,0,1,\n";
        $this->actingAs($user)->from('/trades/import')->post('/trades/import', ['csv_text' => $bad])
            ->assertRedirect('/trades/import')
            ->assertSessionHasErrors('csv')
            ->assertSessionHasInput('csv_text', trim($bad)); // TrimStrings middleware

        $this->assertSame(0, $user->trades()->count());
    }

    public function test_artisan_import_by_email_and_by_id(): void
    {
        $user = User::factory()->create();
        $path = base_path('tests/fixtures/tsm/trades.csv');

        $this->artisan('trades:import', ['file' => $path, '--user' => $user->email])
            ->expectsOutputToContain('38 new, 0 already imported')->assertSuccessful();
        $this->artisan('trades:import', ['file' => $path, '--user' => (string) $user->id])
            ->expectsOutputToContain('0 new, 38 already imported')->assertSuccessful();
    }

    public function test_artisan_import_refuses_unknown_users_and_unreadable_files(): void
    {
        $user = User::factory()->create();

        $this->artisan('trades:import', ['file' => base_path('tests/fixtures/tsm/trades.csv'), '--user' => 'nobody@example.com'])->assertFailed();
        $this->artisan('trades:import', ['file' => '/no/such/file.csv', '--user' => $user->email])->assertFailed();
    }
}
