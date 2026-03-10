<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ServerException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Guzzle retry middleware on ApiClient.
 *
 * These tests build handler stacks that include both the retry middleware
 * and MockHandler, so the full retry pipeline is exercised.
 */
class RetryMiddlewareTest extends TestCase
{
    /**
     * Create an ApiClient whose Guzzle handler stack includes the retry middleware
     * from buildRetryMiddleware() plus the given mock responses.
     *
     * @param Response[] $responses   Queued mock responses
     * @param array      $history     Passed by reference - collects request/response pairs
     * @param array      $config      Config overrides
     * @return ApiClient
     */
    private function createClientWithRetry(array $responses, array &$history = [], array $config = []): ApiClient
    {
        $client = new ApiClient(array_merge([
            'client_id'  => 'test-client',
            'rate_limit' => [
                'strategy'   => 'exception',
                'auto_retry' => true,
                'max_retries' => 3,
            ],
        ], $config));

        // Extract the retry middleware via reflection
        $ref = new \ReflectionMethod($client, 'buildRetryMiddleware');
        $retryMiddleware = $ref->invoke($client);

        // Build a stack with mock handler + retry middleware + history.
        // History must be OUTER (pushed after retry) to capture all attempts -
        // when inner to retry, the promise chain only records the final response.
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push($retryMiddleware, 'retry_429');
        $stack->push(Middleware::history($history), 'history');

        // Inject the custom Guzzle client
        $reflection = new \ReflectionClass($client);
        $prop       = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler'     => $stack,
            'http_errors' => false,
        ]));

        return $client;
    }

    private function createValidToken(): Token
    {
        return Token::fromArray([
            'access_token'  => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at'    => time() + 3600,
            'scope'         => implode(' ', Scope::all()),
        ]);
    }

    // ---------------------------------------------------------------
    // Retry on 429
    // ---------------------------------------------------------------

    public function testRetries429ThenSucceeds(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['name' => 'TestChar'])),
        ], $history);
        $client->withToken($this->createValidToken());

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('TestChar', $response->data()['name']);
        $this->assertCount(2, $history, 'Should have made 2 requests (1 retry)');
    }

    public function testRetriesMultiple429sThenSucceeds(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $history);
        $client->withToken($this->createValidToken());

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(3, $history, 'Should have made 3 requests (2 retries)');
    }

    public function testThrowsRateLimitExceptionAfterMaxRetries(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
        ], $history);
        $client->withToken($this->createValidToken());

        $this->expectException(RateLimitException::class);

        $client->get('/character');
    }

    public function testMaxRetriesIsConfigurable(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
        ], $history, [
            'rate_limit' => [
                'strategy'    => 'exception',
                'auto_retry'  => true,
                'max_retries' => 1,
            ],
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(RateLimitException::class);

        $client->get('/character');
    }

    // ---------------------------------------------------------------
    // Retry on 503
    // ---------------------------------------------------------------

    public function testRetries503ThenSucceeds(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(200, [], json_encode(['ok' => true])),
        ], $history);
        $client->withToken($this->createValidToken());

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(2, $history);
    }

    public function testThrowsServerExceptionAfterMax503Retries(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
            new Response(503, [], json_encode(['error' => 'Maintenance'])),
        ], $history);
        $client->withToken($this->createValidToken());

        $this->expectException(ServerException::class);

        $client->get('/profile');
    }

    // ---------------------------------------------------------------
    // No retry for other errors
    // ---------------------------------------------------------------

    public function testDoesNotRetry400(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(400, [], json_encode(['error' => 'Bad request'])),
        ], $history);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/profile');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertCount(1, $history, 'Should NOT retry 400 errors');
    }

    public function testDoesNotRetry401(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(401, [], json_encode(['error' => 'Unauthorized'])),
        ], $history);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/profile');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertCount(1, $history, 'Should NOT retry 401 errors');
    }

    public function testDoesNotRetry500(): void
    {
        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(500, [], json_encode(['error' => 'Internal error'])),
        ], $history);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/profile');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertCount(1, $history, 'Should NOT retry 500 errors');
    }

    // ---------------------------------------------------------------
    // Auto-retry disabled
    // ---------------------------------------------------------------

    public function testAutoRetryCanBeDisabled(): void
    {
        $client = new ApiClient([
            'client_id'  => 'test-client',
            'rate_limit' => [
                'strategy'   => 'exception',
                'auto_retry' => false,
            ],
        ]);

        // Create a mock that returns 429 then 200 - if retry were active,
        // the 200 would be returned. With retry disabled, we get the 429.
        $mock  = new MockHandler([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, [], json_encode(['ok' => true])),
        ]);
        $stack = HandlerStack::create($mock);

        $reflection = new \ReflectionClass($client);
        $prop       = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler'     => $stack,
            'http_errors' => false,
        ]));

        $client->withToken($this->createValidToken());

        $this->expectException(RateLimitException::class);

        $client->get('/character');
    }

    // ---------------------------------------------------------------
    // Rate limit recording after retry
    // ---------------------------------------------------------------

    public function testRecordsRateLimitHeadersAfterSuccessfulRetry(): void
    {
        $rateLimitHeaders = [
            'X-Rate-Limit-Policy'       => 'character-request-limit',
            'X-Rate-Limit-Rules'        => 'Account',
            'X-Rate-Limit-Account'      => '5:10:60',
            'X-Rate-Limit-Account-State' => '2:10:0',
        ];

        $history = [];
        $client  = $this->createClientWithRetry([
            new Response(429, ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            new Response(200, $rateLimitHeaders, json_encode(['id' => 'test'])),
        ], $history);
        $client->withToken($this->createValidToken());

        $client->get('/character/TestChar');

        $policy = $client->getRateLimiter()->getPolicy('character-request-limit');
        $this->assertNotNull($policy, 'Rate limit headers from successful retry should be recorded');
    }
}
