<?php

namespace Tests\Feature;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\FakesBlizzard;
use Tests\TestCase;

class BlizzardClientTest extends TestCase
{
    use FakesBlizzard;

    private string $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = $this->tempFile();
    }

    private function client(): BlizzardClient
    {
        return app(BlizzardClient::class);
    }

    public function test_it_is_unconfigured_without_credentials(): void
    {
        config(['services.blizzard.client_id' => null, 'services.blizzard.client_secret' => '']);

        $this->assertFalse($this->client()->configured());
        $this->expectException(BlizzardException::class);
        $this->client()->accessToken();
    }

    public function test_the_token_uses_basic_auth_and_is_cached(): void
    {
        $this->fakeBlizzard();

        $this->assertSame('tok-abc', $this->client()->accessToken());
        $this->assertSame('tok-abc', $this->client()->accessToken());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return str_starts_with($r->url(), 'https://oauth.battle.net/token')
                && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-client-id:test-client-secret-value'))
                && $r['grant_type'] === 'client_credentials';
        });
    }

    public function test_commodities_downloads_to_the_sink_with_the_right_request(): void
    {
        $this->fakeBlizzard();

        $d = $this->client()->commodities('us', $this->sink);

        $this->assertFalse($d->notModified);
        $this->assertSame($this->lastModifiedEpoch(), $d->lastModified);
        $this->assertSame(strlen($this->commoditiesBody()), $d->bytes);
        $this->assertSame($this->commoditiesBody(), file_get_contents($this->sink));
        $this->assertSame(self::LAST_MODIFIED, $d->headers['last-modified']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'us.api.blizzard.com/data/wow/auctions/commodities')
            && str_contains($r->url(), 'namespace=dynamic-us')
            && str_contains($r->url(), 'locale=en_US')
            && $r->hasHeader('Authorization', 'Bearer tok-abc')
            && ! $r->hasHeader('If-Modified-Since'));
    }

    public function test_if_modified_since_is_sent_in_http_date_format_and_a_304_means_unchanged(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::response('', 304)]);

        $d = $this->client()->commodities('us', $this->sink, $this->lastModifiedEpoch());

        $this->assertTrue($d->notModified);
        $this->assertSame($this->lastModifiedEpoch(), $d->lastModified);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'commodities') && $r->hasHeader('If-Modified-Since', self::LAST_MODIFIED));
    }

    public function test_an_expired_token_is_refreshed_once_and_the_call_retried(): void
    {
        $tokens = 0;
        $this->fakeBlizzard([
            self::TOKEN_URL => function () use (&$tokens) {
                $tokens++;

                return Http::response(['access_token' => 'tok-'.$tokens, 'expires_in' => 86399]);
            },
            self::ITEM_URL => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok-2')
                ? Http::response(['name' => 'Arcane Wyrmfish', 'quality' => ['type' => 'EPIC']])
                : Http::response(['code' => 401], 401),
        ]);

        $item = $this->client()->item(238371, 'us');

        $this->assertSame(['name' => 'Arcane Wyrmfish', 'quality' => 'EPIC'], $item);
        $this->assertSame(2, $tokens);
    }

    public function test_a_second_401_gives_up_instead_of_looping(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::response(['code' => 401], 401)]);

        $this->expectException(BlizzardException::class);
        $this->expectExceptionMessage('HTTP 401');
        $this->client()->item(1, 'us');
    }

    public function test_server_errors_and_rate_limits_throw_without_leaking_secrets(): void
    {
        // One fake, answering 429, 500 and 503 in turn (a second Http::fake() would stack, not replace).
        $this->fakeBlizzard([self::COMMODITIES_URL => Http::sequence()
            ->push('boom', 429)->push('boom', 500)->push('boom', 503)]);

        foreach ([429, 500, 503] as $status) {
            try {
                $this->client()->commodities('us', $this->sink);
                $this->fail("HTTP {$status} was accepted");
            } catch (BlizzardException $e) {
                $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
                $this->assertStringNotContainsString('test-client', $e->getMessage());
                $this->assertStringNotContainsString('tok-abc', $e->getMessage());
            }
        }
    }

    public function test_refused_credentials_are_reported_without_the_secret(): void
    {
        $this->fakeBlizzard([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        try {
            $this->client()->accessToken();
            $this->fail('accepted bad credentials');
        } catch (BlizzardException $e) {
            $this->assertStringContainsString('refused the credentials', $e->getMessage());
            $this->assertStringNotContainsString('test-client-secret-value', $e->getMessage());
        }
    }

    public function test_connection_failures_become_blizzard_exceptions(): void
    {
        $this->fakeBlizzard([self::COMMODITIES_URL => fn () => throw new ConnectionException('cURL error 6')]);

        $this->expectException(BlizzardException::class);
        $this->expectExceptionMessage('Could not reach the Blizzard API.');
        $this->client()->commodities('us', $this->sink);
    }

    public function test_item_lookup_parses_name_and_quality_and_maps_404_to_null(): void
    {
        $this->fakeBlizzard();

        $this->assertSame(['name' => 'Practically Pork', 'quality' => 'COMMON'], $this->client()->item(242639, 'us'));
        $this->assertNull($this->client()->item(1, 'us'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/data/wow/item/242639')
            && str_contains($r->url(), 'namespace=static-us') && str_contains($r->url(), 'locale=en_US'));
    }

    public function test_item_names_given_as_a_locale_map_are_read(): void
    {
        $this->fakeBlizzard([self::ITEM_URL => Http::response(['name' => ['en_US' => 'Void-Tempered Leather', 'de_DE' => 'Leer'], 'quality' => ['type' => 'RARE']])]);

        $this->assertSame(['name' => 'Void-Tempered Leather', 'quality' => 'RARE'], $this->client()->item(238511, 'us'));
    }

    public function test_unsupported_regions_are_refused_before_any_request(): void
    {
        $this->fakeBlizzard();

        try {
            $this->client()->commodities('xx', $this->sink);
            $this->fail('accepted an unsupported region');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }
}
