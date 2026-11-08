<?php

namespace Tests\Feature;

use App\Services\AddonPackager;
use App\Services\UploaderBuilds;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AddonPackageTest extends TestCase
{
    private string $source;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/wfl-pkg-'.bin2hex(random_bytes(6));
        $this->source = $base.'/addon';
        $this->dir = $base.'/published';
        mkdir($this->source.'/WowFlipLedger', 0755, true);
        $this->addon('0.3.0', 'print("a")');
        config(['ledger.addon.source' => $this->source]);
        $this->app->bind(AddonPackager::class, fn () => new AddonPackager(dir: $this->dir));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($base));
    }

    private function addon(string $version, string $core, bool $changelog = true): void
    {
        file_put_contents($this->source.'/WowFlipLedger/WowFlipLedger.toc', "## Interface: 120105\r\n## Version: {$version}\r\n\r\nCore.lua\r\n");
        file_put_contents($this->source.'/WowFlipLedger/Core.lua', $core);
        file_put_contents($this->source.'/CHANGELOG.md', "# Changelog\n\n".($changelog ? "## {$version}\n\n- Change.\n" : ''));
    }

    private function published(): ?array
    {
        return (new UploaderBuilds($this->dir))->addon();
    }

    public function test_it_publishes_a_new_version_and_lists_it_for_the_setup_page(): void
    {
        $this->artisan('addon:package')->expectsOutput('Published wowflipledger-addon-0.3.0.zip.')->assertSuccessful();

        $addon = $this->published();
        $this->assertSame('0.3.0', $addon['version']);

        $zip = new \ZipArchive;
        $zip->open($this->dir.'/'.$addon['file']);
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertSame(['WowFlipLedger/Core.lua', 'WowFlipLedger/WowFlipLedger.toc'], $names, 'sorted, folder at the top');
        $this->assertSame('print("a")', $zip->getFromName('WowFlipLedger/Core.lua'));
        $this->assertSame(1577836800, $zip->statName('WowFlipLedger/Core.lua')['mtime']);
        $zip->close();
    }

    public function test_a_rerun_is_byte_identical_and_keeps_the_uploader_lines(): void
    {
        mkdir($this->dir, 0755, true);
        $exe = 'wowflipledger-uploader-0.4.0-windows-amd64.exe';
        file_put_contents($this->dir.'/'.$exe, 'win');
        file_put_contents($this->dir.'/SHA256SUMS', hash('sha256', 'win')."  {$exe}\n");

        $this->artisan('addon:package')->assertSuccessful();
        $first = $this->published()['sha256'];
        touch($this->source.'/WowFlipLedger/Core.lua', time() + 60);

        $this->artisan('addon:package')->expectsOutput('wowflipledger-addon-0.3.0.zip is already published.')->assertSuccessful();

        $this->assertSame($first, $this->published()['sha256'], 'file times do not change the zip');
        $this->assertSame('0.4.0', (new UploaderBuilds($this->dir))->current()['version']);
        $this->assertCount(2, file($this->dir.'/SHA256SUMS'));
    }

    public function test_changed_contents_under_the_same_version_are_refused(): void
    {
        $this->artisan('addon:package')->assertSuccessful();
        $first = $this->published()['sha256'];

        $this->addon('0.3.0', 'print("b")');

        $this->artisan('addon:package')->expectsOutputToContain('Bump the version')->assertFailed();
        $this->assertSame($first, $this->published()['sha256'], 'the published zip is kept');
    }

    public function test_a_bumped_version_replaces_the_old_zip(): void
    {
        $this->artisan('addon:package')->assertSuccessful();
        $this->addon('0.3.1', 'print("b")');

        $this->artisan('addon:package')->assertSuccessful();

        $this->assertSame('0.3.1', $this->published()['version']);
        $this->assertFileDoesNotExist($this->dir.'/wowflipledger-addon-0.3.0.zip');
        $this->assertStringNotContainsString('0.3.0', file_get_contents($this->dir.'/SHA256SUMS'));
    }

    public function test_a_version_without_a_changelog_section_is_refused(): void
    {
        $this->addon('0.3.0', 'print("a")', changelog: false);

        $this->artisan('addon:package')->expectsOutput("addon/CHANGELOG.md has no '## 0.3.0' section.")->assertFailed();
        $this->assertNull($this->published());
    }

    public function test_no_mounted_source_is_skipped_quietly(): void
    {
        config(['ledger.addon.source' => $this->source.'/missing']);

        $this->artisan('addon:package')->assertSuccessful();
        $this->assertNull($this->published());
    }
}
