<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The 429/503 retry middleware ApiClient's constructor installs.
 *
 * Clients are built through the constructor and only the network end of the
 * stack is mocked (MocksInnermostHandler), so `auto_retry` and `max_retries`
 * are exercised as the app sets them. The backoff Guzzle would sleep is read
 * from the recorded attempts instead of waited.
 */
class RetryMiddlewareTest extends TestCase
{
    use MocksInnermostHandler;

    /**
     * @param  array<int, mixed>  $responses  Queued mock responses
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $attempts  Every request that reached the network end
     * @param  array  $rateLimit  `rate_limit` config overrides
     */
    private function createClientWithRetry(array $responses, array &$attempts, array $rateLimit = []): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => array_merge(['strategy' => 'exception'], $rateLimit),
        ]);
        $client->withToken($this->createValidToken());

        $this->mockInnermostHandler($client, $responses, $attempts);

        return $client;
    }

    private function createValidToken(): Token
    {
        return Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]);
    }

    /**
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $attempts
     * @return array<int, int|float|null>
     */
    private function delays(array $attempts): array
    {
        return array_column($attempts, 'delay');
    }

    // ---------------------------------------------------------------
    // Retry on 429
    // ---------------------------------------------------------------

    public function test_retries429_then_succeeds(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['name' => 'TestChar'])),
        ], $attempts);

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('TestChar', $response->data()['name']);
        $this->assertCount(2, $attempts, 'Should have made 2 requests (1 retry)');
    }

    public function test_retries_multiple429s_then_succeeds(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(3, $attempts, 'Should have made 3 requests (2 retries)');
    }

    public function test_throws_rate_limit_exception_after_max_retries(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
        ], $attempts);

        try {
            $client->get('/character');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException) {
            $this->assertCount(4, $attempts, 'the first request plus the default 3 retries');
        }
    }

    public function test_max_retries_is_configurable(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts, ['max_retries' => 1]);

        try {
            $client->get('/character');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException) {
            $this->assertCount(2, $attempts, 'the first request plus 1 retry');
        }
    }

    public function test_the_retry_waits_for_the_retry_after_header_not_the_backoff(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '7'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        $client->get('/profile');

        $this->assertSame([null, 7000], $this->delays($attempts), 'Retry-After 7 s is 7000 ms; the 2 s backoff would be 2000');
    }

    // ---------------------------------------------------------------
    // Retry on 503
    // ---------------------------------------------------------------

    public function test_retries503_then_succeeds(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(2, $attempts);
        $this->assertSame([null, 2000], $this->delays($attempts), 'a 503 carries no Retry-After: the first backoff is 2 s');
    }

    public function test_throws_server_exception_after_max503_retries(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
        ], $attempts);

        try {
            $client->get('/profile');
            $this->fail('Expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(503, $e->getCode());
        }

        $this->assertSame([null, 2000, 4000, 8000], $this->delays($attempts), 'exponential backoff: 2 s, 4 s, 8 s');
    }

    // ---------------------------------------------------------------
    // No retry for other errors
    // ---------------------------------------------------------------

    public function test_does_not_retry400(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(400, [], json_encode(['error' => 'Bad request'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        try {
            $client->get('/profile');
            $this->fail('Expected InvalidRequestException');
        } catch (InvalidRequestException) {
            $this->assertCount(1, $attempts, 'Should NOT retry 400 errors');
        }
    }

    public function test_does_not_retry401(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(401, [], json_encode(['error' => 'Unauthorized'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        try {
            $client->get('/profile');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException) {
            $this->assertCount(1, $attempts, 'Should NOT retry 401 errors');
        }
    }

    public function test_does_not_retry500(): void
    {
        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(500, [], json_encode(['error' => 'Internal error'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts);

        try {
            $client->get('/profile');
            $this->fail('Expected ServerException');
        } catch (ServerException) {
            $this->assertCount(1, $attempts, 'Should NOT retry 500 errors');
        }
    }

    // ---------------------------------------------------------------
    // Auto-retry disabled
    // ---------------------------------------------------------------

    public function test_auto_retry_can_be_disabled(): void
    {
        $attempts = [];
        // The app builds web-request clients this way. A 429 followed by a 200:
        // with retry active the 200 comes back, with it off the 429 is thrown.
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '30'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $attempts, ['auto_retry' => false]);

        try {
            $client->get('/character');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
        }

        $this->assertCount(1, $attempts, 'no retry, so no wait either');
        $this->assertSame([null], $this->delays($attempts));
    }

    // ---------------------------------------------------------------
    // Rate limit recording after retry
    // ---------------------------------------------------------------

    public function test_records_rate_limit_headers_after_successful_retry(): void
    {
        $rateLimitHeaders = [
            'X-Rate-Limit-Policy' => 'character-request-limit',
            'X-Rate-Limit-Rules' => 'Account',
            'X-Rate-Limit-Account' => '5:10:60',
            'X-Rate-Limit-Account-State' => '2:10:0',
        ];

        $attempts = [];
        $client = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, $rateLimitHeaders, json_encode(['id' => 'test'])),
        ], $attempts);

        $client->get('/character/TestChar');

        $policy = $client->getRateLimiter()->getPolicy('character-request-limit');
        $this->assertNotNull($policy, 'Rate limit headers from successful retry should be recorded');
    }
}
