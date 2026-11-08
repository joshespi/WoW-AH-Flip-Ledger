<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Zips addon/WowFlipLedger into the published downloads (storage/app/uploader) and lists it in
 * SHA256SUMS, the same way scripts/build-uploader.sh does: Info-ZIP with -X, sorted file list,
 * fixed file times and modes, so both produce the same bytes. A version is published once: if
 * that version's zip is already there with other contents, it is kept and the addon needs a
 * version bump (a rebuilt addon with the old version is how a stale install goes unnoticed).
 */
class AddonPackager
{
    private const FOLDER = 'WowFlipLedger';

    private const ZIP_NAME = '/^'.UploaderBuilds::ADDON_FILE.'$/D';

    /** 2020-01-01 00:00:00 UTC, the time build-uploader.sh gives every file. */
    private const MTIME = 1577836800;

    public function __construct(private ?string $source = null, private ?string $dir = null)
    {
        $this->source ??= (string) config('ledger.addon.source');
        $this->dir ??= storage_path('app/uploader');
    }

    /** @return array{0: bool, 1: string} [ok, message]; not ok means refused */
    public function package(): array
    {
        $addon = $this->source.'/'.self::FOLDER;

        if (! is_dir($addon)) {
            return [true, "No addon source at {$this->source}."];
        }

        $toc = (string) @file_get_contents($addon.'/'.self::FOLDER.'.toc');

        if (! preg_match('/^## Version:\s*(\d+\.\d+\.\d+)\s*$/m', $toc, $m)) {
            return [false, 'The addon .toc has no "## Version: x.y.z" line.'];
        }

        $version = $m[1];
        $changelog = (string) @file_get_contents($this->source.'/CHANGELOG.md');

        if (! preg_match('/^## '.preg_quote($version, '/').'\s*$/m', $changelog)) {
            return [false, "addon/CHANGELOG.md has no '## {$version}' section."];
        }

        $file = UploaderBuilds::addonFile($version);
        $target = $this->dir.'/'.$file;
        $staging = sys_get_temp_dir().'/wfl-addon-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->dir);

        try {
            $built = $this->zip($addon, $staging);
            $sha = (string) hash_file('sha256', $built);

            if (is_file($target)) {
                if (! hash_equals((string) hash_file('sha256', $target), $sha)) {
                    return [false, "{$file} is already published with other contents. Bump the version in WowFlipLedger.toc and add a CHANGELOG section."];
                }

                $status = [true, "{$file} is already published."];
            } else {
                // Copy next to the target first so the final rename is atomic.
                copy($built, $target.'.part');
                rename($target.'.part', $target);
                $status = [true, "Published {$file}."];
            }

            $this->removeOtherZips($file);
            $this->writeSums($file, $sha);

            return $status;
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** Zip path inside $staging. */
    private function zip(string $addon, string $staging): string
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($addon, \FilesystemIterator::SKIP_DOTS));

        foreach ($items as $item) {
            if (! $item->isFile() || $item->isLink()) {
                continue;
            }

            $rel = self::FOLDER.'/'.str_replace('\\', '/', substr($item->getPathname(), strlen($addon) + 1));
            $copy = $staging.'/'.$rel;

            File::ensureDirectoryExists(dirname($copy));

            copy($item->getPathname(), $copy);
            chmod($copy, 0644);
            touch($copy, self::MTIME);
            $files[] = $rel;
        }

        sort($files, SORT_STRING);

        // Info-ZIP stores local DOS times: TZ=UTC matches the script's container.
        Process::path($staging)->env(['TZ' => 'UTC'])->input(implode("\n", $files)."\n")
            ->run(['zip', '-X', '-q', 'out.zip', '-@'])->throw();

        return $staging.'/out.zip';
    }

    private function removeOtherZips(string $keep): void
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            $path = $this->dir.'/'.$name;

            if ($name !== $keep && preg_match(self::ZIP_NAME, $name) && is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
    }

    /** Replaces the addon line in SHA256SUMS, keeping the uploader builds' lines. */
    private function writeSums(string $file, string $sha): void
    {
        $path = $this->dir.'/'.UploaderBuilds::SUMS;
        $lines = array_filter(
            UploaderBuilds::sumsLines($this->dir),
            fn (string $line) => $line !== '' && ! preg_match('/ [ *]wowflipledger-addon-/', $line),
        );
        $lines[] = "{$sha}  {$file}";

        file_put_contents($path.'.part', implode("\n", $lines)."\n");
        rename($path.'.part', $path);
    }
}
