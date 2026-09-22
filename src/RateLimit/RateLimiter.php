<?php

namespace Braseidon\VaalApi\RateLimit;

use Closure;

/**
 * Tracks rate limit state per policy and provides pre-flight checks.
 *
 * This class is stateful but side-effect-free. It records state from
 * response headers and answers "can I make another request?" questions.
 * It never sleeps, throws, or logs - strategy enforcement happens in
 * the API client.
 *
 * State lives in a RateLimitStore. The default store belongs to this
 * limiter alone; pass a shared one (see RateLimitStore) when separate
 * processes act for the same account, or each process learns about a
 * lockout only by running into it.
 *
 * Recorded state is a snapshot of one response's headers. Every wait is
 * counted down by the seconds elapsed since that response was recorded.
 */
class RateLimiter
{
    private const POLICY_KEY = 'policy:';

    private const PATH_KEY = 'path:';

    private readonly RateLimitStore $store;

    private readonly Closure $clock;

    /** @var array<string, true> Policy names this limiter recorded, for reset() */
    private array $recorded = [];

    /**
     * @param  float  $safetyMargin  Fraction to reduce limits by (0.0-1.0, default 0.2 = 20%)
     * @param  RateLimitStore|null  $store  Where state is kept; defaults to this limiter's own memory
     * @param  Closure|null  $clock  Returns the current Unix time in seconds; defaults to time()
     */
    public function __construct(
        private readonly float $safetyMargin = 0.2,
        ?RateLimitStore $store = null,
        ?Closure $clock = null,
    ) {
        $this->store = $store ?? new InMemoryRateLimitStore;
        $this->clock = $clock ?? fn (): int => time();
    }

    /**
     * Record rate limit state from a response.
     *
     * Call this after every API response to keep the tracker current.
     *
     * @param  array  $headers  Response headers
     * @return RateLimitPolicy|null Parsed policy, or null if no rate limit headers
     */
    public function recordResponse(array $headers): ?RateLimitPolicy
    {
        $policy = RateLimitPolicy::fromHeaders($headers);

        if ($policy !== null) {
            $this->store->put(self::POLICY_KEY.$policy->name, [
                'headers' => self::rateLimitHeaders($headers),
                'recorded_at' => $this->now(),
            ], self::relevantFor($policy));
            $this->recorded[$policy->name] = true;
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
     * @param  string  $policy  Policy name
     */
    public function check(string $policy): RateLimitResult
    {
        $entry = $this->store->get(self::POLICY_KEY.$policy);
        $state = $entry === null ? null : RateLimitPolicy::fromHeaders($entry['headers'] ?? []);

        if ($state === null) {
            return RateLimitResult::unknown();
        }

        $elapsed = max(0, $this->now() - (int) ($entry['recorded_at'] ?? 0));

        // Retry-After takes priority (active 429)
        if ($state->retryAfter !== null && $state->retryAfter - $elapsed > 0) {
            return RateLimitResult::wait($policy, $state->retryAfter - $elapsed, 'Retry-After header active');
        }

        $maxWait = 0;
        $reason = '';

        foreach ($state->rules as $ruleName => $windows) {
            foreach ($windows as $window) {
                $wait = $window->waitSeconds($this->safetyMargin) - $elapsed;

                if ($wait > $maxWait) {
                    $maxWait = $wait;
                    $reason = $window->isPenalized()
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
     * Remember which policy GGG applies to a normalized path, so the next
     * request to that path, from any limiter on the store, is checked first.
     */
    public function rememberPolicyForPath(string $path, string $policy): void
    {
        // The mapping is a fact about GGG's routing, not about any window,
        // so it outlives every penalty.
        $this->store->put(self::PATH_KEY.$path, ['policy' => $policy], 86400);
    }

    /**
     * The policy GGG applied to a normalized path, or '' before the first response.
     */
    public function policyForPath(string $path): string
    {
        return (string) ($this->store->get(self::PATH_KEY.$path)['policy'] ?? '');
    }

    /**
     * Parse headers and immediately check if limited.
     *
     * Convenience method combining recordResponse() and a limit check.
     *
     * @param  array  $headers  Response headers
     */
    public function isLimited(array $headers): bool
    {
        $policy = $this->recordResponse($headers);

        if ($policy === null) {
            return false;
        }

        return ! $this->check($policy->name)->canProceed;
    }

    /**
     * Get wait seconds from response headers.
     *
     * Convenience method for simple "how long do I wait?" queries.
     *
     * @param  array  $headers  Response headers
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
     * Get the last-known state for a policy, as its response reported it.
     *
     * @param  string  $policy  Policy name
     */
    public function getPolicy(string $policy): ?RateLimitPolicy
    {
        $entry = $this->store->get(self::POLICY_KEY.$policy);

        return $entry === null ? null : RateLimitPolicy::fromHeaders($entry['headers'] ?? []);
    }

    /**
     * Clear the state this limiter recorded.
     */
    public function reset(): void
    {
        foreach (array_keys($this->recorded) as $policy) {
            $this->store->forget(self::POLICY_KEY.$policy);
        }

        $this->recorded = [];
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    /**
     * The rate limit headers of a response, flattened to strings.
     *
     * @param  array  $headers  Raw headers
     * @return array<string, string>
     */
    private static function rateLimitHeaders(array $headers): array
    {
        $kept = [];

        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);

            if (str_starts_with($lower, 'x-rate-limit-') || $lower === 'retry-after') {
                $kept[$lower] = is_array($value) ? implode(',', $value) : (string) $value;
            }
        }

        return $kept;
    }

    /**
     * Seconds until nothing in this policy's state can still cause a wait.
     */
    private static function relevantFor(RateLimitPolicy $policy): int
    {
        $longest = $policy->retryAfter ?? 0;

        foreach ($policy->rules as $windows) {
            foreach ($windows as $window) {
                $longest = max($longest, $window->period, $window->activePenalty);
            }
        }

        return max(1, $longest);
    }
}
