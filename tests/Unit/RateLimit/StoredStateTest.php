<?php

namespace Braseidon\VaalApi\Tests\Unit\RateLimit;

use Braseidon\VaalApi\RateLimit\InMemoryRateLimitStore;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * State another writer left in the store, read back as written: an entry from
 * v1.2.0 (headers and an int recorded_at, no hit times, no block), and hit
 * times another process wrote after the latest policy entry.
 */
class StoredStateTest extends TestCase
{
    private const POLICY = 'stash-request-limit';

    private float $now = 1_000_000.0;

    private function limiter(InMemoryRateLimitStore $store): RateLimiter
    {
        return new RateLimiter(0.0, $store, fn (): float => $this->now);
    }

    /**
     * A stored policy entry's headers, flattened to lowercase strings as recordResponse() keeps them.
     *
     * @return array<string, string>
     */
    private static function storedHeaders(string $state, ?int $retryAfter = null): array
    {
        return array_filter([
            'x-rate-limit-policy' => self::POLICY,
            'x-rate-limit-rules' => 'Account',
            'x-rate-limit-account' => '15:10:60,30:300:300',
            'x-rate-limit-account-state' => $state,
            'retry-after' => $retryAfter === null ? null : (string) $retryAfter,
        ], fn ($value) => $value !== null);
    }

    public function test_a_full_window_from_a_v1_2_0_entry_waits_a_period_after_it_was_recorded(): void
    {
        $store = new InMemoryRateLimitStore;
        // v1.2.0 wrote the policy entry only, with an int timestamp.
        $store->put('policy:'.self::POLICY, [
            'headers' => self::storedHeaders('15:10:0,15:300:0'),
            'recorded_at' => (int) $this->now,
        ], 300);

        $this->now += 4.0;
        $limiter = $this->limiter($store);

        // No hit times: all fifteen of GGG's count age out 10 s (plus the 1 s edge pad) after the entry.
        $this->assertSame(7, $limiter->check(self::POLICY)->waitSeconds);
        $this->assertSame(0, $limiter->capacity(self::POLICY));

        $this->now += 7.0;
        $this->assertTrue($limiter->check(self::POLICY)->canProceed);
        $this->assertSame(15, $limiter->capacity(self::POLICY));
    }

    public function test_a_penalty_in_a_v1_2_0_entry_holds_with_no_block_key(): void
    {
        $store = new InMemoryRateLimitStore;
        $store->put('policy:'.self::POLICY, [
            'headers' => self::storedHeaders('16:10:60,16:300:0', retryAfter: 60),
            'recorded_at' => (int) $this->now,
        ], 300);

        $this->now += 20.0;

        $this->assertSame(40, $this->limiter($store)->check(self::POLICY)->waitSeconds, 'GGG\'s stated wait, counted from the entry, no pad');
        $this->assertSame(0, $this->limiter($store)->capacity(self::POLICY));
    }

    public function test_a_hit_written_after_the_latest_entry_is_not_one_of_the_hits_its_header_counted(): void
    {
        $store = new InMemoryRateLimitStore;
        $recordedAt = $this->now;
        // The latest entry: GGG counted three hits; this limiter recorded the response itself.
        $store->put('policy:'.self::POLICY, [
            'headers' => self::storedHeaders('3:10:0,3:300:0'),
            'recorded_at' => $recordedAt,
        ], 301);
        // Another process recorded a later hit whose policy entry did not survive.
        $store->put('hits:'.self::POLICY, ['at' => [$recordedAt, $recordedAt + 2.0]], 301);

        $this->now = $recordedAt + 3.0;

        // GGG's three at the entry (one of them recorded, two unseen) plus the later hit: four, eleven left.
        $this->assertSame(11, $this->limiter($store)->capacity(self::POLICY));
    }
}
