<?php

namespace Braseidon\VaalApi\Tests\Unit\RateLimit;

use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Rate limit state shared between limiters through a store.
 *
 * An app that builds a new client per request (one per web request, one per
 * queue job) only avoids GGG's lockouts if every client reads the state the
 * others recorded. Stored state is a snapshot of one response's headers, so
 * a later reader must count down the time elapsed since it was recorded.
 */
class SharedRateLimitStateTest extends TestCase
{
    private array $fixtures;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->fixtures = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/rate-limit-headers.json'),
            true,
        );
    }

    private function limiter(InMemoryRateLimitStore $store): RateLimiter
    {
        return new RateLimiter(0.0, $store, fn (): int => $this->now);
    }

    public function test_a_second_limiter_sees_the_penalty_the_first_recorded(): void
    {
        $store = new InMemoryRateLimitStore;
        $this->limiter($store)->recordResponse($this->fixtures['penalized']);

        $result = $this->limiter($store)->check('character-list-request-limit');

        $this->assertFalse($result->canProceed);
        $this->assertSame(45, $result->waitSeconds);
    }

    public function test_a_penalty_counts_down_from_when_it_was_recorded(): void
    {
        $store = new InMemoryRateLimitStore;
        $this->limiter($store)->recordResponse($this->fixtures['penalized']);

        $this->now += 30;
        $this->assertSame(15, $this->limiter($store)->check('character-list-request-limit')->waitSeconds);

        // Retry-After has run out; the fixture's 5-per-300s window (5/5) still holds
        $this->now += 16;
        $this->assertSame(254, $this->limiter($store)->check('character-list-request-limit')->waitSeconds);

        $this->now += 254;
        $this->assertTrue($this->limiter($store)->check('character-list-request-limit')->canProceed);
    }

    public function test_a_full_window_counts_down_from_when_it_was_recorded(): void
    {
        $store = new InMemoryRateLimitStore;
        $this->limiter($store)->recordResponse($this->fixtures['at-limit-window1']);

        $this->now += 4;

        $this->assertSame(6, $this->limiter($store)->check('character-list-request-limit')->waitSeconds);
    }

    public function test_the_path_to_policy_map_is_shared(): void
    {
        $store = new InMemoryRateLimitStore;
        $this->limiter($store)->rememberPolicyForPath('/character', 'character-list-request-limit');

        $this->assertSame('character-list-request-limit', $this->limiter($store)->policyForPath('/character'));
        $this->assertSame('', $this->limiter($store)->policyForPath('/stash/{league}'));
    }

    public function test_limiters_on_separate_stores_share_nothing(): void
    {
        $this->limiter(new InMemoryRateLimitStore)->recordResponse($this->fixtures['penalized']);

        $this->assertTrue($this->limiter(new InMemoryRateLimitStore)->check('character-list-request-limit')->canProceed);
    }
}
