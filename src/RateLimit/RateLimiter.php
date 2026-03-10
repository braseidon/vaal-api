<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * Tracks rate limit state per policy and provides pre-flight checks.
 *
 * This class is stateful but side-effect-free. It records state from
 * response headers and answers "can I make another request?" questions.
 * It never sleeps, throws, or logs - strategy enforcement happens in
 * the API client.
 *
 * State is per-process and not shared between workers. This is acceptable
 * because GGG's response headers always report the current server-side state,
 * so the limiter self-corrects after every response.
 */
class RateLimiter
{
    /** @var array<string, RateLimitPolicy> Last-known state per policy name */
    private array $policies = [];

    /**
     * @param float $safetyMargin Fraction to reduce limits by (0.0-1.0, default 0.2 = 20%)
     */
    public function __construct(
        private readonly float $safetyMargin = 0.2,
    ) {}

    /**
     * Record rate limit state from a response.
     *
     * Call this after every API response to keep the tracker current.
     *
     * @param array $headers Response headers
     * @return RateLimitPolicy|null Parsed policy, or null if no rate limit headers
     */
    public function recordResponse(array $headers): ?RateLimitPolicy
    {
        $policy = RateLimitPolicy::fromHeaders($headers);

        if ($policy !== null) {
            $this->policies[$policy->name] = $policy;
        }

        return $policy;
    }

    /**
     * Check whether a request can proceed for the given policy.
     *
     * Returns a result indicating whether to proceed or wait. If no data
     * exists for this policy (first request), returns "proceed" since we
     * can't know the limits yet.
     *
     * @param string $policy Policy name
     * @return RateLimitResult
     */
    public function check(string $policy): RateLimitResult
    {
        if (!isset($this->policies[$policy])) {
            return RateLimitResult::unknown();
        }

        $state = $this->policies[$policy];

        // Retry-After takes priority (active 429)
        if ($state->retryAfter !== null && $state->retryAfter > 0) {
            return RateLimitResult::wait($policy, $state->retryAfter, 'Retry-After header active');
        }

        $maxWait = 0;
        $reason  = '';

        foreach ($state->rules as $ruleName => $windows) {
            foreach ($windows as $window) {
                $wait = $window->waitSeconds($this->safetyMargin);

                if ($wait > $maxWait) {
                    $maxWait = $wait;
                    $reason  = $window->isPenalized()
                        ? "Rule '{$ruleName}' is penalized for {$wait}s"
                        : "Rule '{$ruleName}' at limit ({$window->currentHits}/{$window->maxHits})";
                }
            }
        }

        if ($maxWait > 0) {
            return RateLimitResult::wait($policy, $maxWait, $reason);
        }

        return RateLimitResult::proceed($policy);
    }

    /**
     * Parse headers and immediately check if limited.
     *
     * Convenience method combining recordResponse() and a limit check.
     *
     * @param array $headers Response headers
     * @return bool
     */
    public function isLimited(array $headers): bool
    {
        $policy = $this->recordResponse($headers);

        if ($policy === null) {
            return false;
        }

        return !$this->check($policy->name)->canProceed;
    }

    /**
     * Get wait seconds from response headers.
     *
     * Convenience method for simple "how long do I wait?" queries.
     *
     * @param array $headers Response headers
     * @return int
     */
    public function getWaitSeconds(array $headers): int
    {
        $policy = $this->recordResponse($headers);

        if ($policy === null) {
            return 0;
        }

        return $this->check($policy->name)->waitSeconds;
    }

    /**
     * Get the last-known state for a policy.
     *
     * @param string $policy Policy name
     * @return RateLimitPolicy|null
     */
    public function getPolicy(string $policy): ?RateLimitPolicy
    {
        return $this->policies[$policy] ?? null;
    }

    /**
     * Clear all tracked state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->policies = [];
    }
}
