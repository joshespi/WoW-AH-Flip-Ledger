<?php

namespace Tests\Feature;

use App\Livewire\SetupProgress;
use App\Models\Upload;
use App\Models\User;
use App\Services\UploaderBuilds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wfl-builds-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->app->bind(UploaderBuilds::class, fn () => new UploaderBuilds($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    /** @param array<string, string> $files name => content; the sums file lists them all */
    private function publish(array $files, array $sumOverrides = []): void
    {
        $sums = '';

        foreach ($files as $name => $content) {
            file_put_contents("{$this->dir}/{$name}", $content);
            $sums .= ($sumOverrides[$name] ?? hash('sha256', $content))."  {$name}\n";
        }

        file_put_contents($this->dir.'/SHA256SUMS', $sums);
    }

    private function upload(User $user, string $status, ?string $error = null): void
    {
        Upload::create(['user_id' => $user->id, 'source' => 'manual', 'received_at' => now(), 'bytes' => 1, 'status' => $status, 'error' => $error]);
    }

    public function test_lists_only_verified_files_of_the_newest_version(): void
    {
        $this->publish([
            'wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'old',
            'wowflipledger-uploader-0.2.0-windows-amd64.exe' => 'win',
            'wowflipledger-uploader-0.2.0-darwin-arm64' => 'mac',
            'wowflipledger-uploader-0.2.0-linux-amd64' => 'tampered',
            'notes.txt' => 'not a build name',
        ], ['wowflipledger-uploader-0.2.0-linux-amd64' => str_repeat('a', 64)]);

        $current = (new UploaderBuilds($this->dir))->current();

        $this->assertSame('0.2.0', $current['version']);
        $this->assertEqualsCanonicalizing(['windows-amd64', 'darwin-arm64'], array_keys($current['builds']));
        $this->assertSame(hash('sha256', 'win'), $current['builds']['windows-amd64']['sha256']);
        $this->assertSame(3, $current['builds']['windows-amd64']['size']);
    }

    public function test_nothing_published_is_null(): void
    {
        $builds = new UploaderBuilds($this->dir);

        $this->assertNull($builds->current());
        $this->assertNull($builds->path('windows-amd64'));
        $this->assertNull($builds->sumsPath());
    }

    public function test_platform_guess_from_user_agent(): void
    {
        $this->assertSame('windows-amd64', UploaderBuilds::guess('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
        $this->assertSame('darwin-arm64', UploaderBuilds::guess('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'));
        $this->assertSame('linux-amd64', UploaderBuilds::guess('Mozilla/5.0 (X11; Linux x86_64)'));
        $this->assertNull(UploaderBuilds::guess('Mozilla/5.0 (Linux; Android 14)'));
        $this->assertNull(UploaderBuilds::guess(null));
    }

    public function test_download_serves_only_published_known_builds(): void
    {
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win-bytes']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->get('/download/windows-amd64')->assertOk();
        $this->assertSame('win-bytes', file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('wowflipledger-uploader-0.1.0-windows-amd64.exe', $res->headers->get('content-disposition'));

        $this->get('/download/darwin-arm64')->assertNotFound();      // known name, not published
        $this->get('/download/windows-arm64')->assertNotFound();     // not published
        $this->get('/download/..%2F..%2F.env')->assertNotFound();
        $this->get('/download/windows-amd64.exe')->assertNotFound();
        $this->get('/download/SHA256SUMS')->assertOk()->assertHeader('content-type', 'text/plain; charset=UTF-8');
    }

    public function test_download_is_404_when_nothing_is_published_and_requires_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/download/windows-amd64')->assertNotFound();
        $this->get('/download/SHA256SUMS')->assertNotFound();

        auth()->logout();
        $this->get('/download/windows-amd64')->assertRedirect('/login');
    }

    public function test_setup_page_shows_builds_checksums_and_the_warnings(): void
    {
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win']);

        $this->actingAs(User::factory()->create())
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
            ->get('/setup')->assertOk()
            ->assertSee('Version 0.1.0')->assertSee(hash('sha256', 'win'))->assertSee('suggested')
            ->assertSee('SmartScreen')->assertSee('Gatekeeper')
            ->assertSee(route('download.build', 'windows-amd64'), false);
    }

    public function test_nothing_is_suggested_when_the_os_is_unknown(): void
    {
        $this->publish([
            'wowflipledger-uploader-0.1.0-darwin-amd64' => 'mac',
            'wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win',
        ]);

        $this->actingAs(User::factory()->create())
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get('/setup')->assertOk()->assertDontSee('suggested')->assertSee('macOS (Intel)')->assertSee('Windows (64-bit)');

        // The example command, shown with a new token, defaults to the Windows build.
        $this->post('/setup/token', ['name' => 'x'])->assertSee('.\wowflipledger-uploader-0.1.0-windows-amd64.exe setup', false);
    }

    public function test_setup_page_without_a_published_build(): void
    {
        $this->actingAs(User::factory()->create())->get('/setup')->assertOk()->assertSee('not been published');
    }

    public function test_the_addon_zip_is_listed_verified_and_newest_wins(): void
    {
        $this->publish([
            'wowflipledger-addon-0.1.0.zip' => 'old',
            'wowflipledger-addon-0.2.0.zip' => 'new',
            'wowflipledger-addon-0.3.0.zip' => 'tampered',
        ], ['wowflipledger-addon-0.3.0.zip' => str_repeat('b', 64)]);

        $addon = (new UploaderBuilds($this->dir))->addon();

        $this->assertSame('0.2.0', $addon['version']);
        $this->assertSame(hash('sha256', 'new'), $addon['sha256']);
        $this->assertSame(3, $addon['size']);
    }

    public function test_the_addon_ignores_odd_names_and_missing_files(): void
    {
        // Odd names are listed with the right hash and present on disk; one listed name has no file.
        $this->publish(['wowflipledger-addon-latest.zip' => 'x', 'wowflipledger-addon-0.1.0.zip.exe' => 'x']);
        file_put_contents($this->dir.'/SHA256SUMS', file_get_contents($this->dir.'/SHA256SUMS').hash('sha256', 'x')."  wowflipledger-addon-0.1.0.zip\n");

        $this->assertNull((new UploaderBuilds($this->dir))->addon());
        $this->assertNull((new UploaderBuilds($this->dir))->addonPath());
    }

    public function test_the_addon_download_serves_only_the_published_zip_and_needs_login(): void
    {
        $this->publish(['wowflipledger-addon-0.1.0.zip' => 'zip-bytes']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->get('/download/addon')->assertOk();
        $this->assertSame('zip-bytes', file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('wowflipledger-addon-0.1.0.zip', $res->headers->get('content-disposition'));

        auth()->logout();
        $this->get('/download/addon')->assertRedirect('/login');
    }

    public function test_the_addon_download_is_404_when_not_published(): void
    {
        $this->actingAs(User::factory()->create())->get('/download/addon')->assertNotFound();
    }

    public function test_the_addon_zip_does_not_count_as_an_uploader_build(): void
    {
        $this->publish(['wowflipledger-addon-0.1.0.zip' => 'zip']);

        $this->assertNull((new UploaderBuilds($this->dir))->current());
        $this->actingAs(User::factory()->create())->get('/download/windows-amd64')->assertNotFound();
    }

    public function test_setup_page_offers_the_addon_with_its_checksum_and_the_one_write_it_makes(): void
    {
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win', 'wowflipledger-addon-0.1.0.zip' => 'zip']);

        $this->actingAs(User::factory()->create())
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
            ->get('/setup')->assertOk()
            ->assertSee('WowFlipLedger addon 0.1.0')->assertSee(hash('sha256', 'zip'))
            ->assertSee(route('download.addon'), false)
            ->assertSee('addon enable')->assertSee('Data.lua')->assertSee('/reload');
    }

    public function test_setup_page_without_a_published_addon(): void
    {
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win']);

        $this->actingAs(User::factory()->create())->get('/setup')->assertOk()->assertSee('addon has not been published');
    }

    public function test_setup_warns_when_the_build_is_older_than_the_minimum(): void
    {
        config(['ledger.min_client_version' => '9.0.0']);
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win']);

        $this->actingAs(User::factory()->create())->get('/setup')->assertSee('older than the minimum');
    }

    public function test_token_is_shown_once_with_the_exact_command_and_never_stored_in_the_session(): void
    {
        $this->publish(['wowflipledger-uploader-0.1.0-windows-amd64.exe' => 'win']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->withHeader('User-Agent', 'Windows')->post('/setup/token', ['name' => 'My PC'])->assertOk();

        $plain = $user->tokens()->sole()->token;
        $this->assertSame(['ingest'], $user->tokens()->sole()->abilities);
        $res->assertSee('.\wowflipledger-uploader-0.1.0-windows-amd64.exe setup --server '.rtrim(url('/'), '/').' --token ', false);
        $this->assertStringNotContainsString('wfl_', json_encode(session()->all()));

        $this->assertNotSame('', $plain);
        $this->get('/setup')->assertDontSee('--token');
    }

    public function test_token_validation_and_cap(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/setup/token', [])->assertSessionHasErrors('name');

        for ($i = 0; $i < User::MAX_TOKENS; $i++) {
            $user->createToken("t$i", ['ingest']);
        }

        $this->post('/setup/token', ['name' => 'one more'])->assertSessionHasErrors('name');
        $this->assertSame(User::MAX_TOKENS, $user->tokens()->count());
    }

    public function test_progress_waits_then_reports_the_first_upload(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->upload($other, Upload::STATUS_OK);

        Livewire::actingAs($user)->test(SetupProgress::class)
            ->assertSee('Waiting for your first upload')->assertSeeHtml('wire:poll.visible.3s');

        $this->upload($user, Upload::STATUS_FAILED, 'Not a TSM file');

        Livewire::actingAs($user)->test(SetupProgress::class)->assertSee('Not a TSM file')->assertSee('Waiting');

        $this->upload($user, Upload::STATUS_OK);

        Livewire::actingAs($user)->test(SetupProgress::class)
            ->assertSee('First upload received')->assertDontSeeHtml('wire:poll');
    }
}
