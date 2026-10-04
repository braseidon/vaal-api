<?php

namespace Braseidon\VaalApi\Tests\Unit\RateLimit;

use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * A full window frees its first slot when the oldest hit in it ages out, not a
 * whole period after the latest response. GGG's state header only counts hits,
 * so the limiter keeps the time each response landed; the header stays the
 * authority whenever it counts more hits than the limiter recorded.
 */
class ExactWaitTest extends TestCase
{
    private const POLICY = 'stash-request-limit';

    private float $now = 1_000_000.0;

    private function limiter(?InMemoryRateLimitStore $store = null): RateLimiter
    {
        return new RateLimiter(0.0, $store ?? new InMemoryRateLimitStore, fn (): float => $this->now);
    }

    /**
     * Headers of a stash read response with the given window state.
     */
    private static function stashRead(string $state, ?int $retryAfter = null): array
    {
        return array_filter([
            'X-Rate-Limit-Policy' => self::POLICY,
            'X-Rate-Limit-Rules' => 'Account',
            'X-Rate-Limit-Account' => '15:10:60,30:300:300',
            'X-Rate-Limit-Account-State' => $state,
            'Retry-After' => $retryAfter === null ? null : (string) $retryAfter,
        ], fn ($value) => $value !== null);
    }

    /**
     * Record $count responses spread evenly over $seconds, GGG's count rising by one each time.
     */
    private function recordSpread(RateLimiter $limiter, int $count, float $seconds, int $alreadyInLongWindow = 0): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $limiter->recordResponse(self::stashRead(sprintf('%d:10:0,%d:300:0', $i, $alreadyInLongWindow + $i)));

            if ($i < $count) {
                $this->now += $seconds / ($count - 1);
            }
        }
    }

    public function test_the_sixteenth_read_waits_until_the_oldest_hit_ages_out(): void
    {
        $limiter = $this->limiter();
        $start = $this->now;
        $this->recordSpread($limiter, 15, 4.0);

        $this->assertEqualsWithDelta($start + 4.0, $this->now, 0.0001);

        $result = $limiter->check(self::POLICY);

        $this->assertFalse($result->canProceed);
        $this->assertSame(7, $result->waitSeconds, 'The first hit landed 4 s before the last, so its slot frees 6 s from now (plus the 1 s edge pad), not 10');
        $this->assertSame(0, $limiter->capacity(self::POLICY));

        $this->now = $start + 11.0;
        $this->assertTrue($limiter->check(self::POLICY)->canProceed);
        $this->assertSame(1, $limiter->capacity(self::POLICY));
    }

    public function test_a_full_window_stays_full_one_second_past_its_edge(): void
    {
        $limiter = $this->limiter();
        $limiter->recordResponse(self::stashRead('15:10:0,15:300:0'));

        // GGG's window resolution is undocumented: the computed edge is not trusted to the second.
        $this->now += 10.0;
        $this->assertFalse($limiter->check(self::POLICY)->canProceed);
        $this->assertSame(1, $limiter->check(self::POLICY)->waitSeconds);
        $this->assertSame(0, $limiter->capacity(self::POLICY));

        $this->now += 1.0;
        $this->assertTrue($limiter->check(self::POLICY)->canProceed);
        $this->assertSame(15, $limiter->capacity(self::POLICY));
    }

    public function test_a_hit_stays_recorded_until_its_padded_edge_passes(): void
    {
        $limiter = $this->limiter();
        $start = $this->now;
        $this->recordSpread($limiter, 30, 0.0);

        // A response landing half a second past the long window's bare edge
        // must not prune the thirty hits: they still hold their padded second.
        $this->now = $start + 300.5;
        $limiter->recordResponse(self::stashRead('1:10:0,1:300:0'));

        $this->assertSame(1, $limiter->check(self::POLICY)->waitSeconds);
        $this->assertSame(0, $limiter->capacity(self::POLICY));
    }

    public function test_the_long_window_frees_when_the_first_of_thirty_hits_ages_out(): void
    {
        $limiter = $this->limiter();
        $start = $this->now;
        $this->recordSpread($limiter, 15, 4.0);
        // Every hit of the first fifteen has left the 10 s window by +14 s.
        $this->now = $start + 14.0;
        $this->recordSpread($limiter, 15, 2.0, alreadyInLongWindow: 15);

        // The last response landed at +16 s; the first, at +0 s, leaves the 300 s window at +300 s (+301 s with the edge pad).
        $result = $limiter->check(self::POLICY);

        $this->assertSame(285, $result->waitSeconds, 'Not 300: the wait counts from the oldest hit, not the latest response');
    }

    public function test_the_header_count_wins_when_it_is_higher_than_the_hits_recorded(): void
    {
        $limiter = $this->limiter();
        $this->recordSpread($limiter, 3, 2.0);

        // Another process (or a hit this limiter never saw) filled the window.
        $this->now += 1.0;
        $limiter->recordResponse(self::stashRead('15:10:0,15:300:0'));

        $result = $limiter->check(self::POLICY);

        $this->assertFalse($result->canProceed, 'Four recorded hits, but GGG counts fifteen');
        $this->assertSame(8, $result->waitSeconds, 'The oldest recorded hit (at 0 s) leaves first, at 10 s plus the 1 s edge pad');
        $this->assertSame(0, $limiter->capacity(self::POLICY));
    }

    public function test_the_header_count_sizes_the_capacity_when_it_is_higher(): void
    {
        $limiter = $this->limiter();
        $this->recordSpread($limiter, 3, 2.0);

        $this->now += 1.0;
        $limiter->recordResponse(self::stashRead('10:10:0,10:300:0'));

        $this->assertSame(5, $limiter->capacity(self::POLICY), 'Ten hits by GGG\'s count leave five, not the eleven our four recorded hits would');
    }

    public function test_hits_the_header_counted_but_we_never_recorded_age_out_a_period_after_that_response(): void
    {
        $limiter = $this->limiter();
        $limiter->recordResponse(self::stashRead('15:10:0,15:300:0'));

        $this->assertSame(11, $limiter->check(self::POLICY)->waitSeconds);

        $this->now += 4.0;
        $this->assertSame(7, $limiter->check(self::POLICY)->waitSeconds);
    }

    public function test_a_restricted_state_forces_its_wait_even_with_hits_to_spare(): void
    {
        $limiter = $this->limiter();
        $limiter->recordResponse(self::stashRead('1:10:60,1:300:0'));

        $result = $limiter->check(self::POLICY);

        $this->assertFalse($result->canProceed);
        $this->assertSame(60, $result->waitSeconds);
        $this->assertSame(0, $limiter->capacity(self::POLICY));

        $this->now += 59.5;
        $this->assertSame(1, $limiter->check(self::POLICY)->waitSeconds);

        $this->now += 0.5;
        $this->assertTrue($limiter->check(self::POLICY)->canProceed);
    }

    public function test_a_later_response_without_the_restriction_does_not_lift_it(): void
    {
        $store = new InMemoryRateLimitStore;
        $limiter = $this->limiter($store);
        $limiter->recordResponse(self::stashRead('16:10:60,16:300:0', retryAfter: 60));

        // A concurrent request GGG answered before the 429 lands after it.
        $this->now += 0.5;
        $limiter->recordResponse(self::stashRead('14:10:0,14:300:0'));

        $result = $this->limiter($store)->check(self::POLICY);

        $this->assertFalse($result->canProceed);
        $this->assertSame(60, $result->waitSeconds);
    }

    public function test_recorded_hits_are_shared_through_the_store(): void
    {
        $store = new InMemoryRateLimitStore;
        $start = $this->now;
        $this->recordSpread($this->limiter($store), 15, 4.0);

        $this->assertSame(7, $this->limiter($store)->check(self::POLICY)->waitSeconds);

        $this->now = $start + 11.0;
        $this->assertSame(1, $this->limiter($store)->capacity(self::POLICY));
    }

    public function test_an_unknown_policy_has_no_capacity_figure(): void
    {
        $this->assertNull($this->limiter()->capacity(self::POLICY));
    }

    public function test_the_safety_margin_shrinks_the_capacity(): void
    {
        $limiter = new RateLimiter(0.4, new InMemoryRateLimitStore, fn (): float => $this->now);
        $limiter->recordResponse(self::stashRead('1:10:0,1:300:0'));

        // 15 * 0.6 = 9 short-window hits, 30 * 0.6 = 18 long-window hits; one used.
        $this->assertSame(8, $limiter->capacity(self::POLICY));
    }
}
