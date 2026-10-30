<?php

namespace App\Services\Blizzard;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Blizzard Game Data API: OAuth client-credentials token (cached until shortly before it
 * expires, refreshed once on a 401) and the two calls we use. Blizzard allows 100 requests/second
 * and 36,000/hour per client; callers pace item lookups well under that.
 */
class BlizzardClient
{
    private const LOCALE = 'en_US';

    public function configured(): bool
    {
        return filled(config('services.blizzard.client_id')) && filled(config('services.blizzard.client_secret'));
    }

    /**
     * Download the region-wide commodities listing to $sinkPath without holding it in memory.
     * Sends If-Modified-Since when given; a 304 means nothing has changed.
     *
     * @throws BlizzardException
     */
    public function commodities(string $region, string $sinkPath, ?int $ifModifiedSince = null): CommoditiesDownload
    {
        $headers = $ifModifiedSince === null ? [] : ['If-Modified-Since' => gmdate('D, d M Y H:i:s', $ifModifiedSince).' GMT'];

        $response = $this->send($region, '/data/wow/auctions/commodities', [
            'namespace' => 'dynamic-'.$region,
            'locale' => self::LOCALE,
        ], function (PendingRequest $request) use ($sinkPath, $headers) {
            return $request->timeout(600)->withHeaders($headers)->withOptions(['sink' => $sinkPath]);
        });

        $headerMap = [];
        foreach ($response->headers() as $name => $values) {
            $headerMap[strtolower($name)] = implode(', ', $values);
        }

        if ($response->status() === 304) {
            return new CommoditiesDownload(true, $this->parseDate($headerMap['last-modified'] ?? null) ?? $ifModifiedSince, 0, $headerMap);
        }

        clearstatcache(true, $sinkPath);

        return new CommoditiesDownload(false, $this->parseDate($headerMap['last-modified'] ?? null), (int) @filesize($sinkPath), $headerMap);
    }

    /**
     * One item's English name and quality, or null when Blizzard doesn't know the id.
     *
     * @return array{name: ?string, quality: ?string}|null
     *
     * @throws BlizzardException
     */
    public function item(int $id, string $region): ?array
    {
        $response = $this->send($region, '/data/wow/item/'.$id, [
            'namespace' => 'static-'.$region,
            'locale' => self::LOCALE,
        ], fn (PendingRequest $request) => $request->timeout(20), allow404: true);

        if ($response->status() === 404) {
            return null;
        }

        $name = $response->json('name');
        $name = is_array($name) ? ($name[self::LOCALE] ?? null) : $name;
        $quality = $response->json('quality.type');

        return [
            'name' => is_string($name) ? $name : null,
            'quality' => is_string($quality) ? $quality : null,
        ];
    }

    /**
     * @throws BlizzardException
     */
    public function accessToken(bool $fresh = false): string
    {
        if (! $this->configured()) {
            throw new BlizzardException('Blizzard credentials are not set (BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET).');
        }

        $key = $this->tokenKey();

        if (! $fresh && is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.blizzard.client_id'), (string) config('services.blizzard.client_secret'))
                ->timeout(30)
                ->post((string) config('services.blizzard.token_url'), ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new BlizzardException('Could not reach the Blizzard login server.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new BlizzardException("Blizzard refused the credentials (HTTP {$response->status()}). Check BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET.");
        }

        // Refresh well before the real expiry (about 24 h).
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /**
     * @param  array<string, string>  $query
     * @param  callable(PendingRequest): PendingRequest  $configure
     *
     * @throws BlizzardException
     */
    private function send(string $region, string $path, array $query, callable $configure, bool $allow404 = false): Response
    {
        if (! array_key_exists($region, config('ledger.regions'))) {
            throw new InvalidArgumentException("Unsupported region '{$region}'.");
        }

        $url = sprintf((string) config('services.blizzard.api_url'), $region).$path;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $configure(Http::withToken($this->accessToken($attempt > 0))->acceptJson())->get($url, $query);
            } catch (ConnectionException) {
                throw new BlizzardException('Could not reach the Blizzard API.');
            }

            // An expired or revoked token: get a new one and try once more.
            if ($response->status() === 401 && $attempt === 0) {
                Cache::forget($this->tokenKey());

                continue;
            }

            break;
        }

        $status = $response->status();

        if ($status === 304 || $response->successful() || ($allow404 && $status === 404)) {
            return $response;
        }

        throw new BlizzardException("Blizzard returned HTTP {$status} for {$path}.");
    }

    private function tokenKey(): string
    {
        return 'blizzard:token:'.sha1((string) config('services.blizzard.client_id'));
    }

    private function parseDate(?string $header): ?int
    {
        $ts = $header === null ? false : strtotime($header);

        return $ts === false ? null : $ts;
    }
}
