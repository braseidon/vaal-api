<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * Result of a pre-flight rate limit check.
 *
 * Returned by RateLimiter::check() before making a request.
 */
readonly class RateLimitResult
{
    /**
     * @param bool   $canProceed  Whether the request can proceed
     * @param int    $waitSeconds Seconds to wait before retrying
     * @param string $policy      Policy name that triggered the limit
     * @param string $reason      Human-readable reason for the wait
     */
    public function __construct(
        public bool   $canProceed,
        public int    $waitSeconds,
        public string $policy,
        public string $reason = '',
    ) {}

    /**
     * Create an "OK to proceed" result.
     *
     * @param string $policy Policy name
     * @return self
     */
    public static function proceed(string $policy): self
    {
        return new self(
            canProceed:  true,
            waitSeconds: 0,
            policy:      $policy,
        );
    }

    /**
     * Create a "must wait" result.
     *
     * @param string $policy  Policy name
     * @param int    $seconds Seconds to wait
     * @param string $reason  Why the wait is required
     * @return self
     */
    public static function wait(string $policy, int $seconds, string $reason): self
    {
        return new self(
            canProceed:  false,
            waitSeconds: $seconds,
            policy:      $policy,
            reason:      $reason,
        );
    }

    /**
     * Create a result for an unknown policy (first request, no data yet).
     *
     * @return self
     */
    public static function unknown(): self
    {
        return new self(
            canProceed:  true,
            waitSeconds: 0,
            policy:      '',
            reason:      'No rate limit data available',
        );
    }
}
