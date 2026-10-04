<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\StashTab;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use Braseidon\VaalApi\RateLimit\RateLimitResult;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * StashResource::getMany() against a fake GGG that enforces a rolling window
 * the way GGG's headers describe it, on a fake clock: no real sleeps, and no
 * request leaves the MockHandler.
 *
 * Every rate limit wait goes through the callback strategy, which logs it and
 * moves the clock forward by exactly the wait it was given.
 */
class ConcurrentStashReadsTest extends TestCase
{
    private const POLICY = 'stash-request-limit';

    private float $now = 1_000_000.0;

    /** @var list<string> "send:{id}", "land:{key}" and "wait:{seconds}", in order */
    private array $events = [];

    /** False: the callback logs the wait but lets no time pass, like a callback that does not sleep. */
    private bool $callbackSleeps = true;

    /** Runs inside the callback after the clock moves: what another process does during the wait. */
    private ?\Closure $duringWait = null;

    /**
     * @param  array  $history  Collects every request that reached the fake
     */
    private function client(FakeGgg $ggg, array &$history, string $strategy = 'callback', ?InMemoryRateLimitStore $store = null, int $maxRetries = 3): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => [
                'strategy' => $strategy,
                'callback' => function (RateLimitResult $result): void {
                    $this->events[] = 'wait:'.$result->waitSeconds;

                    if ($this->callbackSleeps) {
                        $this->now += $result->waitSeconds;
                    }

                    if ($this->duringWait !== null) {
                        ($this->duringWait)();
                    }
                },
                'safety_margin' => 0.0,
                'auto_retry' => true,
                'max_retries' => $maxRetries,
                'store' => $store ?? new InMemoryRateLimitStore,
                'clock' => fn (): float => $this->now,
            ],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        // The production retry middleware stays in the stack: a round must not use it.
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 200, $ggg)));
        $stack->push((new \ReflectionMethod($client, 'buildRetryMiddleware'))->invoke($client), 'retry_429');
        $stack->push(Middleware::history($history), 'history');
        // Logs each response as its promise resolves, after the fake has logged its send.
        $stack->push(Middleware::mapResponse(function ($response) {
            $this->events[] = 'land';

            return $response;
        }), 'land');
        (new \ReflectionProperty($client, 'httpClient'))->setValue($client, new GuzzleClient([
            'handler' => $stack,
            'base_uri' => 'https://api.pathofexile.com',
            'http_errors' => false,
        ]));

        return $client;
    }

    private function ggg(string $limits = '15:10:60,30:300:300', int $retryAfter = -1): FakeGgg
    {
        return new FakeGgg($limits, fn (): float => $this->now, $this->events, $retryAfter);
    }

    /**
     * One earlier read, long enough ago to have left the 10 s window, so the
     * limiter knows the policy and its limits before the batch starts.
     */
    private function warmUp(ApiClient $client): void
    {
        $client->stashes('Mirage')->get('warmup');
        $this->now += 20.0;
        $this->events = [];
    }

    /**
     * @return array<string, string>
     */
    private static function ids(int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids["tab-{$i}"] = sprintf('t%02d', $i);
        }

        return $ids;
    }

    /**
     * @return list<int|string> Sends per round and the waits between them, e.g. [15, 'wait:10', 5]
     */
    private function rounds(): array
    {
        $rounds = [];
        $sends = 0;
        $previous = '';

        foreach ($this->events as $event) {
            // A send after a landing starts a new round.
            if (str_starts_with($event, 'send:')) {
                if ($previous === 'land' && $sends > 0) {
                    $rounds[] = $sends;
                    $sends = 0;
                }
                $sends++;
            } elseif (str_starts_with($event, 'wait:')) {
                if ($sends > 0) {
                    $rounds[] = $sends;
                    $sends = 0;
                }
                $rounds[] = $event;
            }

            $previous = $event;
        }

        if ($sends > 0) {
            $rounds[] = $sends;
        }

        return $rounds;
    }

    public function test_twenty_reads_under_fifteen_per_ten_seconds_go_fifteen_then_wait_then_five(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        $this->assertSame([15, 'wait:11', 5], $this->rounds());
        $this->assertSame(0, $ggg->refused, 'GGG never refused a request');
        $this->assertTrue($batch->succeeded());
        $this->assertSame(array_keys(self::ids(20)), array_keys($batch->results), 'Results keep the caller\'s keys and order');
        $this->assertContainsOnlyInstancesOf(StashTab::class, $batch->results);
        $this->assertSame('t07', $batch->results['tab-7']->id());

        // Concurrent: all fifteen of the first round went out before any landed.
        $this->assertSame(
            array_merge(array_fill(0, 15, 'send'), array_fill(0, 15, 'land')),
            array_map(fn (string $event): string => explode(':', $event)[0], array_slice($this->events, 0, 30)),
        );
    }

    public function test_the_first_read_goes_alone_while_the_limits_are_unknown(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        $this->assertSame([1, 14, 'wait:11', 5], $this->rounds());
        $this->assertSame(0, $ggg->refused);
        $this->assertCount(20, $batch->results);
    }

    public function test_the_long_window_paces_rounds_after_the_short_one(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);

        $batch = $client->stashes('Mirage')->getMany(self::ids(32));

        // 1 warm-up hit + 29 = 30 in the 300 s window. Every window edge
        // carries the limiter's 1 s pad. The warm-up landed 20 s before the
        // batch, so its slot frees 281 s after round one; the first round's
        // fifteen free 301 s after it, 20 s later.
        $this->assertSame([15, 'wait:11', 14, 'wait:270', 1, 'wait:20', 2], $this->rounds());
        $this->assertSame(0, $ggg->refused);
        $this->assertCount(32, $batch->results);
    }

    public function test_a_429_mid_round_stops_the_batch_until_its_wait_is_over(): void
    {
        $history = [];
        $ggg = $this->ggg('15:10:60', retryAfter: 60);
        $client = $this->client($ggg, $history);
        $this->warmUp($client);

        // Another process spent ten hits the limiter never heard about.
        $ggg->foreignHits(10);

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        // Five fit; the sixth broke the window and drew the 60 s penalty, and
        // the other nine of the round were refused under it. Nothing more was
        // sent until the penalty ended, and no 429 was retried inside the
        // round; then the ten refused and the five never sent went together.
        $this->assertSame(10, $ggg->refused);
        $this->assertSame([15, 'wait:60', 15], $this->rounds());
        $this->assertTrue($batch->succeeded());
        $this->assertCount(20, $batch->results);
    }

    public function test_a_429_inside_a_round_is_not_retried_by_the_middleware(): void
    {
        $history = [];
        // Retry-After 0 would let the retry middleware resend at once.
        $ggg = $this->ggg('15:10:60', retryAfter: 0);
        $client = $this->client($ggg, $history);
        $this->warmUp($client);
        $ggg->foreignHits(10);

        $client->stashes('Mirage')->getMany(self::ids(20));

        // The penalty field still holds the batch for 60 s.
        $this->assertSame([15, 'wait:60', 15], $this->rounds());
        $this->assertSame(10, $ggg->refused);
    }

    public function test_a_429_with_no_rate_limit_headers_ends_the_batch(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);
        $ggg->bareRefusalFor('t03');

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        $this->assertSame([15], $this->rounds(), 'No round after a 429 there is nothing to wait on for');
        $this->assertCount(14, $batch->results);
        $this->assertCount(6, $batch->failures);
        $this->assertContainsOnlyInstancesOf(RateLimitException::class, $batch->failures);
        $this->assertArrayHasKey('tab-3', $batch->failures);
    }

    public function test_a_429_that_states_no_wait_holds_the_batch_for_the_longest_penalty(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);
        $ggg->unexplainedRefusalFor('t03');

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        // No Retry-After and no active penalty: the strictest rule the
        // headers name (the 300 s window's 300 s penalty), never a guess
        // that the short window has room again.
        $this->assertSame([15, 'wait:300', 6], $this->rounds());
        $this->assertSame(1, $ggg->refused);
        $this->assertTrue($batch->succeeded());
        $this->assertSame('t03', $batch->results['tab-3']->id());
        // tab-3 landed after tab-15; results still come back in the caller's order.
        $this->assertSame(array_keys(self::ids(20)), array_keys($batch->results));
    }

    public function test_a_round_waits_again_when_another_process_restricts_the_policy_during_its_wait(): void
    {
        $history = [];
        $store = new InMemoryRateLimitStore;
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history, store: $store);
        $this->warmUp($client);

        // During the first wait, another process acting for the account is held for 30 s.
        $this->duringWait = function () use ($store): void {
            $this->duringWait = null;
            (new RateLimiter(0.0, $store, fn (): float => $this->now))->restrict(self::POLICY, 30, 'another process');
        };

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        $this->assertSame([15, 'wait:11', 'wait:30', 5], $this->rounds(), 'Nothing goes out while the restriction the wait ended under holds');
        $this->assertTrue($batch->succeeded());
    }

    public function test_a_callback_that_does_not_wait_gets_one_request_not_another_wait(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);
        $this->callbackSleeps = false;

        $client->stashes('Mirage')->getMany(self::ids(20));

        // As get() after the same strategy: one request, never the callback again in a loop.
        $this->assertSame([15, 'wait:11', 1], array_slice($this->rounds(), 0, 3));
    }

    public function test_a_failed_read_reports_the_wait_the_limiter_holds_after_a_429_that_stated_none(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history, maxRetries: 0);
        $this->warmUp($client);
        $ggg->unexplainedRefusalFor('t03');

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        // The limiter holds the policy's longest penalty (300 s); the failure says so, not a flat 60.
        $this->assertInstanceOf(RateLimitException::class, $batch->failures['tab-3']);
        $this->assertSame(300, $batch->failures['tab-3']->getRetryAfter());
    }

    public function test_the_exception_strategy_reports_the_unsent_reads_as_failures(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history, strategy: 'exception');
        $this->warmUp($client);

        $batch = $client->stashes('Mirage')->getMany(self::ids(20));

        $this->assertSame([15], $this->rounds());
        $this->assertCount(15, $batch->results);
        $this->assertSame(['tab-16', 'tab-17', 'tab-18', 'tab-19', 'tab-20'], array_keys($batch->failures));
        $this->assertContainsOnlyInstancesOf(RateLimitException::class, $batch->failures);
        $this->assertSame(11, $batch->failures['tab-16']->getRetryAfter());
    }

    public function test_one_missing_tab_fails_alone(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $ggg->missing('t02');
        $client = $this->client($ggg, $history);
        $this->warmUp($client);

        $settled = [];
        $batch = $client->stashes('Mirage')->getMany(self::ids(3), function (string $key, StashTab|VaalApiException $result) use (&$settled): void {
            $settled[$key] = $result::class;
        });

        $this->assertSame(['tab-1', 'tab-3'], array_keys($batch->results));
        $this->assertInstanceOf(ResourceNotFoundException::class, $batch->failures['tab-2']);
        $this->assertSame(StashTab::class, $settled['tab-1']);
        $this->assertSame(ResourceNotFoundException::class, $settled['tab-2']);
    }

    public function test_substash_pairs_read_the_child_path(): void
    {
        $history = [];
        $ggg = $this->ggg();
        $client = $this->client($ggg, $history);
        $this->warmUp($client);

        $batch = $client->stashes('Mirage')->getMany(['parent' => 'p01', 'child' => ['p01', 'c01']]);

        $paths = array_map(fn (array $entry): string => $entry['request']->getUri()->getPath(), array_slice($history, 1));
        $this->assertSame(['/stash/Mirage/p01', '/stash/Mirage/p01/c01'], $paths);
        $this->assertSame('c01', $batch->results['child']->id());
    }

    public function test_every_response_in_a_round_updates_the_shared_store(): void
    {
        $history = [];
        $store = new InMemoryRateLimitStore;
        $client = $this->client($this->ggg(), $history, store: $store);
        $this->warmUp($client);

        $client->stashes('Mirage')->getMany(self::ids(5));

        // A limiter in another process, reading the same store: five hits in
        // the short window, six in the long one.
        $other = new RateLimiter(0.0, $store, fn (): float => $this->now);
        $this->assertSame(10, $other->capacity(self::POLICY));
        $this->assertSame('stash-request-limit', $other->policyForPath('/stash/{league}/{id}'));
        $this->assertNotNull($client->getLastRateLimitHeaders());
    }
}

/**
 * GGG's rolling window, as its rate limit headers describe it, on the test's clock.
 *
 * Every request counts as a hit, refused ones included. A request that takes
 * a window past its limit, or arrives while one is restricted, gets a 429
 * with Retry-After and the window's penalty in its state header.
 */
class FakeGgg
{
    public int $refused = 0;

    /** @var list<array{0: int, 1: int, 2: int}> max hits, period, penalty */
    private array $windows;

    /** @var list<float> */
    private array $hits = [];

    private float $restrictedUntil = 0.0;

    /** @var array<string, true> */
    private array $missing = [];

    /** @var array<string, true> */
    private array $bare = [];

    /** @var array<string, true> */
    private array $unexplained = [];

    /**
     * @param  string  $limits  The X-Rate-Limit-Account value, e.g. "15:10:60,30:300:300"
     * @param  \Closure(): float  $clock
     * @param  list<string>  $events  The test's event log, appended to on every request
     * @param  int  $retryAfter  Retry-After on a 429; -1 sends the remaining restriction
     */
    public function __construct(
        private readonly string $limits,
        private readonly \Closure $clock,
        private array &$events,
        private readonly int $retryAfter = -1,
    ) {
        $this->windows = array_map(
            fn (string $window): array => array_map('intval', explode(':', $window)),
            explode(',', $limits),
        );
    }

    public function foreignHits(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->hits[] = ($this->clock)();
        }
    }

    public function missing(string $id): void
    {
        $this->missing[$id] = true;
    }

    public function bareRefusalFor(string $id): void
    {
        $this->bare[$id] = true;
    }

    /**
     * The next read of this id draws a 429 with the rate limit headers but no
     * Retry-After and no active penalty; later reads succeed.
     */
    public function unexplainedRefusalFor(string $id): void
    {
        $this->unexplained[$id] = true;
    }

    public function __invoke(RequestInterface $request, array $options): Response
    {
        $now = ($this->clock)();
        $segments = explode('/', $request->getUri()->getPath());
        $id = end($segments);
        $this->events[] = "send:{$id}";
        $this->hits[] = $now;

        if (isset($this->bare[$id])) {
            $this->refused++;

            return new Response(429, [], json_encode(['error' => ['message' => 'Rate limit exceeded']]));
        }

        if (isset($this->unexplained[$id])) {
            unset($this->unexplained[$id]);
            $this->refused++;

            return new Response(429, $this->headers($now), json_encode(['error' => ['message' => 'Rate limit exceeded']]));
        }

        $penalties = [];

        foreach ($this->windows as $i => [$max, $period, $penalty]) {
            if ($this->count($period, $now) > $max) {
                $penalties[$i] = $penalty;
                $this->restrictedUntil = max($this->restrictedUntil, $now + $penalty);
            }
        }

        if ($this->restrictedUntil > $now) {
            $this->refused++;
            $remaining = (int) ceil($this->restrictedUntil - $now);

            return new Response(429, $this->headers($now, $penalties, $remaining) + [
                'Retry-After' => (string) ($this->retryAfter < 0 ? $remaining : $this->retryAfter),
            ], json_encode(['error' => ['message' => 'Rate limit exceeded']]));
        }

        if (isset($this->missing[$id])) {
            return new Response(404, $this->headers($now), json_encode(['error' => ['message' => 'Resource not found']]));
        }

        return new Response(200, $this->headers($now), json_encode(['stash' => ['id' => $id, 'name' => $id, 'type' => 'PremiumStash', 'items' => []]]));
    }

    private function count(int $period, float $now): int
    {
        return count(array_filter($this->hits, fn (float $at): bool => $at > $now - $period));
    }

    /**
     * @param  array<int, int>  $penalties  Window index => penalty it just drew
     */
    private function headers(float $now, array $penalties = [], int $remaining = 0): array
    {
        $state = [];

        foreach ($this->windows as $i => [, $period]) {
            $active = isset($penalties[$i]) || ($remaining > 0 && $penalties === [] && $i === 0) ? $remaining : 0;
            $state[] = sprintf('%d:%d:%d', $this->count($period, $now), $period, $active);
        }

        return [
            'X-Rate-Limit-Policy' => 'stash-request-limit',
            'X-Rate-Limit-Rules' => 'Account',
            'X-Rate-Limit-Account' => $this->limits,
            'X-Rate-Limit-Account-State' => implode(',', $state),
        ];
    }
}
