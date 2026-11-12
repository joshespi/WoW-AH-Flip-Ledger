<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/** Token-authenticated API calls. Each one forgets the guards: the auth manager caches the resolved user, and tests switch tokens. */
trait CallsApi
{
    /** @param  array<string, string>  $headers */
    protected function getWithToken(string $url, ?string $token, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($url, $headers + ($token === null ? [] : ['Authorization' => 'Bearer '.$token]));
    }

    /** @param  array<string, string>  $headers */
    protected function postIngest(string $body, string $token, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/v1/ingest', [], [], [], $this->transformHeadersToServerVars($headers + [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/octet-stream',
        ]), $body);
    }
}
