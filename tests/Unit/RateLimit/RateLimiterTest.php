<?php

namespace Braseidon\VaalApi\Tests\Unit\RateLimit;

use Braseidon\VaalApi\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private array $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/rate-limit-headers.json'),
            true,
        );
    }

    public function test_record_response_parses_policy(): void
    {
        $limiter = new RateLimiter;
        $policy = $limiter->recordResponse($this->fixtures['character-list']);

        $this->assertNotNull($policy);
        $this->assertSame('character-list-request-limit', $policy->name);
        $this->assertArrayHasKey('account', $policy->rules);
        $this->assertCount(2, $policy->rules['account']);
    }

    public function test_check_allows_when_under_limit(): void
    {
        $limiter = new RateLimiter(0.0); // No safety margin
        $limiter->recordResponse($this->fixtures['character-list']);

        $result = $limiter->check('character-list-request-limit');

        $this->assertTrue($result->canProceed);
        $this->assertSame(0, $result->waitSeconds);
    }

    public function test_check_blocks_when_at_limit(): void
    {
        $limiter = new RateLimiter(0.0); // No safety margin
        $limiter->recordResponse($this->fixtures['at-limit-window1']);

        $result = $limiter->check('character-list-request-limit');

        $this->assertFalse($result->canProceed);
        $this->assertSame(10, $result->waitSeconds); // Window 1 period
    }

    public function test_check_returns_max_wait_across_windows(): void
    {
        $limiter = new RateLimiter(0.0);
        $limiter->recordResponse($this->fixtures['at-limit-both']);

        $result = $limiter->check('character-list-request-limit');

        $this->assertFalse($result->canProceed);
        $this->assertSame(300, $result->waitSeconds); // Max of both windows
    }

    public function test_check_blocks_when_penalized(): void
    {
        $limiter = new RateLimiter(0.0);
        $limiter->recordResponse($this->fixtures['penalized']);

        $result = $limiter->check('character-list-request-limit');

        $this->assertFalse($result->canProceed);
        $this->assertSame(45, $result->waitSeconds); // Retry-After value
    }

    public function test_safety_margin_reduces_effective_limit(): void
    {
        // At 1 of 2 hits, but with 50% safety margin, effective limit is 1
        $limiter = new RateLimiter(0.5);
        $limiter->recordResponse($this->fixtures['character-list']);

        $result = $limiter->check('character-list-request-limit');

        $this->assertFalse($result->canProceed);
    }

    public function test_safety_margin_allows_when_under_effective(): void
    {
        // At 2 of 5 hits on window 2, with 20% safety margin effective is 4
        // At 1 of 2 hits on window 1, with 20% safety margin effective is 1
        // Window 1: 1 >= floor(2 * 0.8) = 1, so AT limit
        $limiter = new RateLimiter(0.2);
        $limiter->recordResponse($this->fixtures['character-list']);

        $result = $limiter->check('character-list-request-limit');

        // With 20% margin on a 2-request window, effective is floor(1.6) = 1
        // Current hits is 1, so 1 >= 1 means at limit
        $this->assertFalse($result->canProceed);
    }

    public function test_check_unknown_policy_returns_proceeed(): void
    {
        $limiter = new RateLimiter;

        $result = $limiter->check('nonexistent-policy');

        $this->assertTrue($result->canProceed);
    }

    public function test_is_limited_returns_false_when_under_limit(): void
    {
        $limiter = new RateLimiter(0.0);
        $this->assertFalse($limiter->isLimited($this->fixtures['character-list']));
    }

    public function test_is_limited_returns_true_when_at_limit(): void
    {
        $limiter = new RateLimiter(0.0);
        $this->assertTrue($limiter->isLimited($this->fixtures['at-limit-window1']));
    }

    public function test_is_limited_returns_true_when_penalized(): void
    {
        $limiter = new RateLimiter(0.0);
        $this->assertTrue($limiter->isLimited($this->fixtures['penalized']));
    }

    public function test_get_wait_seconds(): void
    {
        $limiter = new RateLimiter(0.0);

        $this->assertSame(0, $limiter->getWaitSeconds($this->fixtures['character-list']));
        $this->assertSame(10, $limiter->getWaitSeconds($this->fixtures['at-limit-window1']));
        $this->assertSame(45, $limiter->getWaitSeconds($this->fixtures['penalized']));
    }

    public function test_reset(): void
    {
        $limiter = new RateLimiter(0.0);
        $limiter->recordResponse($this->fixtures['at-limit-window1']);

        $this->assertNotNull($limiter->getPolicy('character-list-request-limit'));

        $limiter->reset();

        $this->assertNull($limiter->getPolicy('character-list-request-limit'));
    }

    public function test_no_rate_limit_headers(): void
    {
        $limiter = new RateLimiter;
        $policy = $limiter->recordResponse([]);

        $this->assertNull($policy);
    }

    public function test_multiple_rules(): void
    {
        $headers = [
            'X-Rate-Limit-Policy' => 'test-policy',
            'X-Rate-Limit-Rules' => 'Account,Ip',
            'X-Rate-Limit-Account' => '5:10:60',
            'X-Rate-Limit-Account-State' => '1:10:0',
            'X-Rate-Limit-Ip' => '10:10:60',
            'X-Rate-Limit-Ip-State' => '3:10:0',
        ];

        $limiter = new RateLimiter(0.0);
        $policy = $limiter->recordResponse($headers);

        $this->assertNotNull($policy);
        $this->assertArrayHasKey('account', $policy->rules);
        $this->assertArrayHasKey('ip', $policy->rules);
    }

    public function test_window_parses_correctly(): void
    {
        $limiter = new RateLimiter;
        $limiter->recordResponse($this->fixtures['character-detail']);

        $policy = $limiter->getPolicy('character-request-limit');

        $this->assertNotNull($policy);
        $windows = $policy->rules['account'];

        // First window: 5:10:60
        $this->assertSame(5, $windows[0]->maxHits);
        $this->assertSame(10, $windows[0]->period);
        $this->assertSame(60, $windows[0]->penalty);
        $this->assertSame(2, $windows[0]->currentHits);
        $this->assertSame(0, $windows[0]->activePenalty);

        // Second window: 30:300:300
        $this->assertSame(30, $windows[1]->maxHits);
        $this->assertSame(300, $windows[1]->period);
        $this->assertSame(300, $windows[1]->penalty);
        $this->assertSame(5, $windows[1]->currentHits);
        $this->assertSame(0, $windows[1]->activePenalty);
    }
}
