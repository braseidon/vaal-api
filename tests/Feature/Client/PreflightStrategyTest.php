<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\RateLimit\RateLimitResult;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use Braseidon\VaalApi\Tests\Support\RecordedSleeps;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

require_once __DIR__.'/../../Support/sleep-shim.php';

/**
 * The `callback` and `sleep` rate limit strategies at get()'s pre-flight check.
 *
 * Stash Value's snapshot job hands in a callback (`$onRateLimit`); the CLI
 * waits with `sleep`. Both must receive the wait the limiter holds, then send
 * the request. The clock is fixed, so the wait is exact: the 10 s window plus
 * the limiter's 1 s edge pad.
 */
class PreflightStrategyTest extends TestCase
{
    use MocksInnermostHandler;

    private const LIST_AT_LIMIT = [
        'X-Rate-Limit-Policy' => 'character-list-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '2:10:60,5:300:300',
        'X-Rate-Limit-Account-State' => '2:10:0,2:300:0',
    ];

    private const NOW = 1_700_000_000.0;

    protected function tearDown(): void
    {
        RecordedSleeps::stop();
    }

    /**
     * A client whose first /character response fills the 10 s window, so the
     * second get() must wait at pre-flight.
     *
     * @param  array<string, mixed>  $rateLimit
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $attempts
     */
    private function clientAtLimit(array $rateLimit, array &$attempts): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => $rateLimit + [
                'safety_margin' => 0.0,
                'auto_retry' => false,
                'clock' => fn (): float => self::NOW,
            ],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $this->mockInnermostHandler($client, [
            new Response(200, self::LIST_AT_LIMIT, json_encode(['characters' => []])),
            new Response(200, self::LIST_AT_LIMIT, json_encode(['characters' => []])),
        ], $attempts);

        $client->get('/character');

        return $client;
    }

    public function test_the_callback_strategy_hands_the_wait_to_the_callback_then_sends(): void
    {
        $received = [];
        $attempts = [];
        $client = $this->clientAtLimit([
            'strategy' => 'callback',
            'callback' => function (RateLimitResult $result) use (&$received): void {
                $received[] = $result;
            },
        ], $attempts);

        $this->assertSame([], $received, 'The first request had room and must not call back');

        $client->get('/character');

        $this->assertCount(1, $received);
        $this->assertFalse($received[0]->canProceed);
        $this->assertSame('character-list-request-limit', $received[0]->policy);
        $this->assertSame(11, $received[0]->waitSeconds);
        $this->assertCount(2, $attempts, 'The request goes out once the callback returns');
    }

    public function test_the_sleep_strategy_sleeps_the_wait_then_sends(): void
    {
        $attempts = [];
        $client = $this->clientAtLimit(['strategy' => 'sleep'], $attempts);

        RecordedSleeps::start();
        $client->get('/character');

        $this->assertSame([11], RecordedSleeps::stop());
        $this->assertCount(2, $attempts, 'The request goes out after the sleep');
    }
}
