<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Two ApiClient instances sharing one rate limit store, as an app gets when
 * each web request or queue job builds its own client.
 */
class SharedRateLimitStoreTest extends TestCase
{
    private const LIST_AT_LIMIT = [
        'X-Rate-Limit-Policy' => 'character-list-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '2:10:60,5:300:300',
        'X-Rate-Limit-Account-State' => '2:10:0,2:300:0',
    ];

    private const LIST_PENALIZED = [
        'X-Rate-Limit-Policy' => 'character-list-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '2:10:60,5:300:300',
        'X-Rate-Limit-Account-State' => '3:10:60,5:300:0',
    ];

    /**
     * @param  array  $responses  Queued mock responses (or callables)
     * @param  array  $history  Collects every request the client sent
     */
    private function client(InMemoryRateLimitStore $store, array $responses, array &$history, bool $autoRetry = false): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => [
                'strategy' => 'exception',
                'safety_margin' => 0.0,
                'auto_retry' => $autoRetry,
                'store' => $store,
            ],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $stack = HandlerStack::create(new MockHandler($responses));
        if ($autoRetry) {
            $stack->push((new \ReflectionMethod($client, 'buildRetryMiddleware'))->invoke($client), 'retry_429');
        }
        $stack->push(Middleware::history($history), 'history');

        (new \ReflectionProperty($client, 'httpClient'))->setValue($client, new GuzzleClient([
            'handler' => $stack,
            'http_errors' => false,
        ]));

        return $client;
    }

    public function test_a_second_client_refuses_before_sending_when_the_first_filled_the_window(): void
    {
        $store = new InMemoryRateLimitStore;
        $firstHistory = [];
        $this->client($store, [
            new Response(200, self::LIST_AT_LIMIT, json_encode(['characters' => []])),
        ], $firstHistory)->get('/character');

        $secondHistory = [];
        $second = $this->client($store, [
            new Response(200, self::LIST_AT_LIMIT, json_encode(['characters' => []])),
        ], $secondHistory);

        try {
            $second->get('/character');
            $this->fail('Expected the pre-flight check to refuse the request');
        } catch (RateLimitException $e) {
            $this->assertSame(10, $e->getRetryAfter());
        }

        $this->assertCount(0, $secondHistory, 'The second client must not send a request GGG will refuse');
    }

    public function test_a_retried_429_is_shared_before_the_retry_waits(): void
    {
        $store = new InMemoryRateLimitStore;
        $seenDuringRetry = null;
        $history = [];

        $this->client($store, [
            new Response(429, self::LIST_PENALIZED + ['Retry-After' => '0'], json_encode(['error' => 'Rate limited'])),
            function (RequestInterface $request) use ($store, &$seenDuringRetry): Response {
                $seenDuringRetry = (new RateLimiter(0.0, $store))->check('character-list-request-limit');

                return new Response(200, self::LIST_AT_LIMIT, json_encode(['characters' => []]));
            },
        ], $history, autoRetry: true)->get('/character');

        $this->assertCount(2, $history);
        $this->assertNotNull($seenDuringRetry);
        $this->assertFalse($seenDuringRetry->canProceed, 'Other processes must see the lockout while this one waits it out');
    }
}
