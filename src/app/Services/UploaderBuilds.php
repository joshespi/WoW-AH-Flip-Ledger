<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The published uploader builds, and the in-game addon zip, in storage/app/uploader (copied there
 * from uploader/dist by scripts/build-uploader.sh). Only files named in SHA256SUMS, matching the build naming scheme
 * and whose real hash agrees with the sums file are offered, so a half-published directory
 * serves nothing rather than something wrong. Downloads are chosen by platform key from this
 * list; no part of a request ever becomes a path.
 */
class UploaderBuilds
{
    public const SUMS = 'SHA256SUMS';

    private const LINE = '/^([0-9a-f]{64}) [ *](wowflipledger-uploader-(\d+\.\d+\.\d+)-(windows|darwin|linux)-(amd64|arm64)(?:\.exe)?)$/D';

    /** The addon zip's name; group 1 is the version. AddonPackager names and prunes zips by it. */
    public const ADDON_FILE = 'wowflipledger-addon-(\d+\.\d+\.\d+)\.zip';

    private const ADDON_LINE = '/^([0-9a-f]{64}) [ *]('.self::ADDON_FILE.')$/D';

    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= storage_path('app/uploader');
    }

    /**
     * Keyed 'windows-amd64' etc.; null when nothing valid is published.
     *
     * @return ?array{version: string, builds: array<string, array{file: string, sha256: string, size: int, os: string, arch: string}>}
     */
    public function current(): ?array
    {
        $byVersion = [];

        foreach ($this->verified(self::LINE) as [[, $sha, $file, $version, $os, $arch], $path]) {
            $byVersion[$version]["$os-$arch"] = ['file' => $file, 'sha256' => $sha, 'size' => (int) filesize($path), 'os' => $os, 'arch' => $arch];
        }

        $version = self::newest($byVersion);

        return $version === null ? null : ['version' => $version, 'builds' => $byVersion[$version]];
    }

    /**
     * The in-game addon zip listed in SHA256SUMS (newest version whose real hash agrees); null
     * when none is published. Same trust rule as the builds: listed, named like the build
     * script names it, and the bytes match.
     *
     * @return ?array{version: string, file: string, sha256: string, size: int}
     */
    public function addon(): ?array
    {
        $found = [];

        foreach ($this->verified(self::ADDON_LINE) as [[, $sha, $file, $version], $path]) {
            $found[$version] = ['version' => $version, 'file' => $file, 'sha256' => $sha, 'size' => (int) filesize($path)];
        }

        $version = self::newest($found);

        return $version === null ? null : $found[$version];
    }

    /** The highest version among the keys, or null when there are none. */
    private static function newest(array $byVersion): ?string
    {
        $versions = array_map('strval', array_keys($byVersion));
        usort($versions, 'version_compare');

        return $versions === [] ? null : end($versions);
    }

    public function addonPath(): ?string
    {
        $addon = $this->addon();

        return $addon === null ? null : $this->dir.'/'.$addon['file'];
    }

    /**
     * SHA256SUMS entries matching $pattern whose file exists, is not a link and has the listed hash.
     *
     * @return \Generator<int, array{0: list<string>, 1: string}> [regex match, absolute path]
     */
    private function verified(string $pattern): \Generator
    {
        foreach (self::sumsLines($this->dir) as $line) {
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }

            $path = $this->dir.'/'.$m[2];

            if (is_file($path) && ! is_link($path) && hash_equals($m[1], $this->sha256($path))) {
                yield [$m, $path];
            }
        }
    }

    public static function addonFile(string $version): string
    {
        return "wowflipledger-addon-{$version}.zip";
    }

    /** @return list<string> the lines of $dir/SHA256SUMS; none when it is missing */
    public static function sumsLines(string $dir): array
    {
        $sums = @file_get_contents($dir.'/'.self::SUMS);

        return $sums === false ? [] : preg_split('/\R/', trim($sums));
    }

    /**
     * A build's real hash, remembered per file version (path, size, modification and change
     * time; ctime also moves when a copy keeps the old mtime): a download or the setup page would
     * otherwise read every binary on every request.
     */
    private function sha256(string $path): string
    {
        $key = 'uploader:sha256:'.md5($path.'|'.filesize($path).'|'.filemtime($path).'|'.filectime($path));

        return Cache::remember($key, now()->addDays(30), fn () => (string) hash_file('sha256', $path));
    }

    /** Absolute path of a build, or null. $platform is only ever compared with known keys. */
    public function path(string $platform): ?string
    {
        $build = ($this->current()['builds'] ?? [])[$platform] ?? null;

        return $build === null ? null : $this->dir.'/'.$build['file'];
    }

    public function sumsPath(): ?string
    {
        return $this->current() === null ? null : $this->dir.'/'.self::SUMS;
    }

    /** The build most likely to suit a browser, from its User-Agent; null when unsure. */
    public static function guess(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'Windows') => 'windows-amd64',
            str_contains($ua, 'Macintosh') => 'darwin-arm64',
            str_contains($ua, 'Linux') && ! str_contains($ua, 'Android') => 'linux-amd64',
            default => null,
        };
    }
}
