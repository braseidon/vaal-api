<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * A single rate limit time window within a policy rule.
 *
 * GGG uses multiple windows per rule. For example, character list has:
 * - Window 1: 2 requests per 10 seconds, 60 second penalty
 * - Window 2: 5 requests per 300 seconds, 300 second penalty
 *
 * Both windows are enforced simultaneously.
 */
readonly class RateLimitWindow
{
    /**
     * @param int $maxHits       Maximum allowed requests in this window
     * @param int $period        Window duration in seconds
     * @param int $penalty       Penalty duration in seconds if limit is exceeded
     * @param int $currentHits   Current request count in this window
     * @param int $activePenalty Remaining penalty seconds (0 = no penalty)
     */
    public function __construct(
        public int $maxHits,
        public int $period,
        public int $penalty,
        public int $currentHits   = 0,
        public int $activePenalty = 0,
    ) {}

    /**
     * The effective max hits after applying a safety margin.
     *
     * A margin of 0.2 means we treat a 10-request limit as 8,
     * staying below the threshold to avoid penalties.
     *
     * @param float $safetyMargin Fraction to reduce the limit by (0.0-1.0)
     * @return int
     */
    public function effectiveMaxHits(float $safetyMargin): int
    {
        return (int) floor($this->maxHits * (1 - $safetyMargin));
    }

    /**
     * Whether this window is currently penalized (locked out).
     *
     * @return bool
     */
    public function isPenalized(): bool
    {
        return $this->activePenalty > 0;
    }

    /**
     * Whether current usage has hit or exceeded the effective limit.
     *
     * @param float $safetyMargin Safety margin fraction
     * @return bool
     */
    public function isAtLimit(float $safetyMargin): bool
    {
        return $this->currentHits >= $this->effectiveMaxHits($safetyMargin);
    }

    /**
     * Seconds to wait before this window allows another request.
     *
     * Returns the active penalty if penalized, the window period if
     * at the limit, or 0 if requests are still available.
     *
     * @param float $safetyMargin Safety margin fraction
     * @return int
     */
    public function waitSeconds(float $safetyMargin): int
    {
        if ($this->isPenalized()) {
            return $this->activePenalty;
        }

        if ($this->isAtLimit($safetyMargin)) {
            return $this->period;
        }

        return 0;
    }
}
