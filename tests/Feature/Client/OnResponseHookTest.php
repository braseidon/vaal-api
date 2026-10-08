<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Client\ApiResponse;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\ConnectionException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimitResult;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The `on_response` config closure: one call per HTTP response the client
 * receives, in get() and getMany(), retried attempts included.
 *
 * Clients are built through the constructor and only the network end of the
 * stack is mocked (MocksInnermostHandler), so the hook runs as the app
 * installs it. Rate limit waits go through the callback strategy on a fake
 * clock: nothing sleeps.
 */
class OnResponseHookTest extends TestCase
{
    use MocksInnermostHandler;

    private const STASH_HEADERS = [
        'X-Rate-Limit-Policy' => 'stash-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '15:10:60',
        'X-Rate-Limit-Account-State' => '1:10:0',
    ];

    private float $now = 1_000_000.0;

    /** @var list<array{path: string, status: int, headers: array<string, list<string>>, seconds: float}> */
    private array $calls = [];

    /** @var list<array{request: RequestInterface, delay: int|float|null}> */
    private array $attempts = [];

    /**
     * @param  array<int, mixed>  $responses  Queued mock responses
     */
    private function client(array $responses, bool $autoRetry = false, ?\Closure $onResponse = null): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => [
                'strategy' => 'callback',
                'callback' => function (RateLimitResult $result): void {
                    $this->now += $result->waitSeconds;
                },
                'safety_margin' => 0.0,
                'auto_retry' => $autoRetry,
                'store' => new InMemoryRateLimitStore,
                'clock' => fn (): float => $this->now,
            ],
            'on_response' => $onResponse ?? function (string $path, int $status, array $headers, float $seconds): void {
                $this->calls[] = ['path' => $path, 'status' => $status, 'headers' => $headers, 'seconds' => $seconds];
            },
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $this->mockInnermostHandler($client, $responses, $this->attempts);

        return $client;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private static function stashResponse(int $status, array $extra = []): Response
    {
        return new Response($status, self::STASH_HEADERS + $extra, json_encode(['stash' => ['id' => 'x']]));
    }

    /**
     * @param  array{path: string, status: int, headers: array<string, list<string>>, seconds: float}  $call
     */
    private function assertCall(array $call, string $path, int $status): void
    {
        $this->assertSame($path, $call['path']);
        $this->assertSame($status, $call['status']);
        $this->assertSame(['stash-request-limit'], $call['headers']['X-Rate-Limit-Policy']);
        $this->assertIsFloat($call['seconds']);
        $this->assertGreaterThanOrEqual(0.0, $call['seconds']);
    }

    public function test_get_200_calls_the_hook_once(): void
    {
        $client = $this->client([self::stashResponse(200)]);

        $client->get('/stash/Mirage/abc123', ['substash' => 'true']);

        $this->assertCount(1, $this->calls);
        $this->assertCall($this->calls[0], '/stash/Mirage/abc123', 200);
    }

    public function test_get_404_calls_the_hook_once(): void
    {
        $client = $this->client([self::stashResponse(404)]);

        try {
            $client->get('/stash/Mirage/abc123');
            $this->fail('A 404 should throw');
        } catch (ResourceNotFoundException) {
        }

        $this->assertCount(1, $this->calls);
        $this->assertCall($this->calls[0], '/stash/Mirage/abc123', 404);
    }

    public function test_get_with_auto_retry_reports_the_429_then_the_200(): void
    {
        $client = $this->client([
            self::stashResponse(429, ['Retry-After' => '0']),
            self::stashResponse(200),
        ], autoRetry: true);

        $response = $client->get('/stash/Mirage/abc123');

        $this->assertTrue($response->isSuccessful());
        $this->assertCount(2, $this->attempts);
        $this->assertCount(2, $this->calls);
        $this->assertCall($this->calls[0], '/stash/Mirage/abc123', 429);
        $this->assertSame(['0'], $this->calls[0]['headers']['Retry-After']);
        $this->assertCall($this->calls[1], '/stash/Mirage/abc123', 200);
    }

    public function test_a_request_with_no_response_does_not_call_the_hook(): void
    {
        $client = $this->client([
            new ConnectException('timed out', new Request('GET', 'stash/Mirage/abc123')),
        ]);

        try {
            $client->get('/stash/Mirage/abc123');
            $this->fail('A connection error should throw');
        } catch (ConnectionException) {
        }

        $this->assertSame([], $this->calls);
    }

    public function test_get_many_reports_every_pooled_response(): void
    {
        $client = $this->client([
            self::stashResponse(200),
            self::stashResponse(200),
            self::stashResponse(200),
        ]);

        $batch = $client->getMany([
            'a' => '/stash/Mirage/aaa',
            'b' => '/stash/Mirage/bbb',
            'c' => '/stash/Mirage/ccc',
        ]);

        $this->assertCount(3, $batch->results);
        $this->assertCount(3, $this->calls);
        $this->assertCall($this->calls[0], '/stash/Mirage/aaa', 200);
        $this->assertCall($this->calls[1], '/stash/Mirage/bbb', 200);
        $this->assertCall($this->calls[2], '/stash/Mirage/ccc', 200);
    }

    public function test_get_many_reports_a_429_and_the_next_rounds_200_for_the_same_key(): void
    {
        // Round 1 is `a` alone (policy unknown), round 2 sends `b` and `c`
        // together, `b` draws a 429, round 3 re-sends `b` after the wait.
        $client = $this->client([
            self::stashResponse(200),
            new Response(429, [
                'X-Rate-Limit-Account-State' => '15:10:60',
                'Retry-After' => '60',
            ] + self::STASH_HEADERS, json_encode(['error' => ['message' => 'Rate limited']])),
            self::stashResponse(200),
            self::stashResponse(200),
        ]);

        $batch = $client->getMany([
            'a' => '/stash/Mirage/aaa',
            'b' => '/stash/Mirage/bbb',
            'c' => '/stash/Mirage/ccc',
        ]);

        $this->assertSame(['a', 'b', 'c'], array_keys($batch->results));
        $this->assertSame([], $batch->failures);
        $this->assertSame(
            [['/stash/Mirage/aaa', 200], ['/stash/Mirage/bbb', 429], ['/stash/Mirage/ccc', 200], ['/stash/Mirage/bbb', 200]],
            array_map(fn (array $call): array => [$call['path'], $call['status']], $this->calls),
        );

        foreach ($this->calls as $call) {
            $this->assertCall($call, $call['path'], $call['status']);
        }
    }

    public function test_a_throwing_hook_does_not_break_get(): void
    {
        $client = $this->client([self::stashResponse(200)], onResponse: function (): void {
            throw new \RuntimeException('listener failed');
        });

        $response = $client->get('/stash/Mirage/abc123');

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }

    public function test_a_throwing_hook_does_not_break_get_many(): void
    {
        $settled = [];
        $client = $this->client([
            self::stashResponse(200),
            self::stashResponse(200),
            self::stashResponse(200),
        ], onResponse: function (): void {
            throw new \RuntimeException('listener failed');
        });

        $batch = $client->getMany(
            ['a' => '/stash/Mirage/aaa', 'b' => '/stash/Mirage/bbb', 'c' => '/stash/Mirage/ccc'],
            function (int|string $key) use (&$settled): void {
                $settled[] = $key;
            },
        );

        $this->assertSame(['a', 'b', 'c'], $settled);
        $this->assertSame(['a', 'b', 'c'], array_keys($batch->results));
        $this->assertSame([], $batch->failures);
    }

    public function test_no_on_response_leaves_on_stats_unset(): void
    {
        $client = new ApiClient(['client_id' => 'test-client']);

        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $this->assertInstanceOf(GuzzleClient::class, $httpClient);
        $this->assertNull($httpClient->getConfig('on_stats'));
    }
}
