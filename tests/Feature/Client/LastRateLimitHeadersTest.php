<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * getLastRateLimitHeaders() after a response: the app's StashRateLimits and
 * GggApiService::logRequest read it after every call.
 */
class LastRateLimitHeadersTest extends TestCase
{
    use MocksInnermostHandler;

    private const RATE_LIMIT_HEADERS = [
        'X-Rate-Limit-Policy' => 'stash-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '15:10:60,30:300:300',
        'X-Rate-Limit-Account-State' => '1:10:0,1:300:0',
    ];

    /**
     * @param  array<int, mixed>  $responses
     */
    private function client(array $responses): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'auto_retry' => false],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $attempts = [];
        $this->mockInnermostHandler($client, $responses, $attempts);

        return $client;
    }

    public function test_it_holds_only_the_rate_limit_headers_of_the_last_response(): void
    {
        $client = $this->client([
            new Response(200, self::RATE_LIMIT_HEADERS + [
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'abc',
            ], '{"stash": {}}'),
        ]);

        $this->assertNull($client->getLastRateLimitHeaders(), 'Nothing before the first response');

        $client->get('/stash/Mirage/abc1234567');

        $this->assertSame(self::RATE_LIMIT_HEADERS, $client->getLastRateLimitHeaders());
    }

    public function test_it_carries_retry_after_and_is_set_by_an_error_response_too(): void
    {
        $client = $this->client([
            new Response(404, self::RATE_LIMIT_HEADERS + ['Retry-After' => '5'], '{"error": {"code": 1, "message": "Resource not found"}}'),
        ]);

        try {
            $client->get('/stash/Mirage/abc1234567');
            $this->fail('Expected ResourceNotFoundException');
        } catch (ResourceNotFoundException) {
        }

        $this->assertSame(self::RATE_LIMIT_HEADERS + ['Retry-After' => '5'], $client->getLastRateLimitHeaders());
    }

    public function test_a_response_without_rate_limit_headers_clears_the_previous_ones(): void
    {
        $client = $this->client([
            new Response(200, self::RATE_LIMIT_HEADERS, '{"stash": {}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"stash": {}}'),
        ]);

        $client->get('/stash/Mirage/abc1234567');
        $client->get('/stash/Mirage/def1234567');

        $this->assertNull($client->getLastRateLimitHeaders());
    }
}
