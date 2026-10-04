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
 * Per policy, kept in the store so every process acting for the account
 * reads them:
 *
 * - the latest response's headers, which give the windows (hits, period,
 *   penalty) and GGG's own hit count at the moment that response landed;
 * - the time every response landed. GGG's state header counts hits but never
 *   says when they happened, and a full window frees its first slot when its
 *   oldest hit ages out, so the times are what make the wait exact;
 * - a restriction's end time (below).
 *
 * The hit times are a read-then-write on the store with no lock, so two
 * processes recording at the same instant can lose one time. The next
 * response's header count then exceeds the recorded hits and covers it.
 *
 * A hit is timed when its response lands, which is never earlier than GGG
 * received the request, so the wait it yields is never shorter than GGG's.
 * When GGG's header counts more hits than the limiter recorded (another
 * process, a write lost between processes), the extra hits are assumed to
 * have happened the moment that response landed: the latest they could have,
 * so they age out no earlier than GGG lets them. Every window edge worked out
 * from hit times carries a further second (EDGE_PAD_SECONDS).
 *
 * A restricted state (Retry-After, or a non-zero third field in a state
 * header) is kept apart as a "blocked until" time that only ever moves later,
 * so a response that GGG answered before the restriction but that landed
 * after it cannot lift it.
 */
class RateLimiter
{
    private const POLICY_KEY = 'policy:';

    private const PATH_KEY = 'path:';

    private const HITS_KEY = 'hits:';

    private const BLOCK_KEY = 'block:';

    /**
     * Seconds added past every window edge worked out from hit times.
     *
     * GGG documents neither whether a window rolls or resets on a fixed
     * boundary nor the resolution of the times it counts hits by: a window
     * kept in whole seconds holds a hit up to a second longer than its landing
     * time says, and a request sent at the computed edge would draw a 429.
     * Production runs a safety margin of 0.0, so nothing else absorbs that
     * second. Waits GGG's headers state (Retry-After, an active penalty) are
     * GGG's own figures and take no pad.
     */
    private const EDGE_PAD_SECONDS = 1;

    private readonly RateLimitStore $store;

    private readonly Closure $clock;

    /** @var array<string, true> Policy names this limiter recorded, for reset() */
    private array $recorded = [];

    /**
     * @param  float  $safetyMargin  Fraction to reduce limits by (0.0-1.0, default 0.2 = 20%)
     * @param  RateLimitStore|null  $store  Where state is kept; defaults to this limiter's own memory
     * @param  Closure|null  $clock  Returns the current Unix time in seconds (int or float); defaults to microtime(true)
     */
    public function __construct(
        private readonly float $safetyMargin = 0.2,
        ?RateLimitStore $store = null,
        ?Closure $clock = null,
    ) {
        $this->store = $store ?? new InMemoryRateLimitStore;
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    /**
     * Record rate limit state from a response.
     *
     * Call this once for every response GGG sent, including a 429: each one
     * is a hit GGG counted.
     *
     * @param  array  $headers  Response headers
     * @return RateLimitPolicy|null Parsed policy, or null if no rate limit headers
     */
    public function recordResponse(array $headers): ?RateLimitPolicy
    {
        $policy = RateLimitPolicy::fromHeaders($headers);

        if ($policy === null) {
            return null;
        }

        $now = $this->now();
        $longest = self::longestPeriod($policy) + self::EDGE_PAD_SECONDS;

        $this->store->put(self::POLICY_KEY.$policy->name, [
            'headers' => self::rateLimitHeaders($headers),
            'recorded_at' => $now,
        ], self::relevantFor($policy));

        $hits = array_values(array_filter(
            $this->hitTimes($policy->name),
            fn (float $at): bool => $at + $longest > $now,
        ));
        $hits[] = $now;
        $this->store->put(self::HITS_KEY.$policy->name, ['at' => $hits], max(1, $longest));

        [$until, $reason] = self::restriction($policy, $now);
        $block = $this->store->get(self::BLOCK_KEY.$policy->name);

        if ($until > $now && $until > (float) ($block['until'] ?? 0)) {
            $this->store->put(self::BLOCK_KEY.$policy->name, [
                'until' => $until,
                'reason' => $reason,
            ], max(1, (int) ceil($until - $now)));
        }

        $this->recorded[$policy->name] = true;

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
        $state = $this->state($policy);

        if ($state === null) {
            return RateLimitResult::unknown();
        }

        [$now, $blockedUntil, $blockReason, $windows] = $state;

        if ($blockedUntil > $now) {
            return RateLimitResult::wait($policy, self::seconds($blockedUntil - $now), $blockReason);
        }

        $maxWait = 0.0;
        $reason = '';

        foreach ($windows as [$ruleName, $window, $expiries]) {
            $wait = self::waitForOne($window, $expiries, $this->safetyMargin, $now);

            if ($wait > $maxWait) {
                $maxWait = $wait;
                $reason = sprintf("Rule '%s' at limit (%d/%d)", $ruleName, count($expiries), $window->maxHits);
            }
        }

        if ($maxWait > 0) {
            return RateLimitResult::wait($policy, self::seconds($maxWait), $reason);
        }

        return RateLimitResult::proceed($policy);
    }

    /**
     * How many requests may go out for this policy right now without
     * overfilling any of its windows (safety margin applied).
     *
     * Null before the first response for the policy: the limits are unknown,
     * so a caller sends one request and learns them. Zero while restricted.
     */
    public function capacity(string $policy): ?int
    {
        $state = $this->state($policy);

        if ($state === null) {
            return null;
        }

        [$now, $blockedUntil, , $windows] = $state;

        if ($blockedUntil > $now) {
            return 0;
        }

        $capacity = PHP_INT_MAX;

        foreach ($windows as [, $window, $expiries]) {
            $capacity = min($capacity, max(0, $window->effectiveMaxHits($this->safetyMargin) - count($expiries)));
        }

        // A policy whose headers carried no usable window has no figure to send against.
        return $capacity === PHP_INT_MAX ? null : $capacity;
    }

    /**
     * Hold every request for this policy for the given seconds from now.
     *
     * For a restriction the headers did not state (a 429 without Retry-After
     * or a penalty). Like a stated one, it only ever moves the end later.
     */
    public function restrict(string $policy, int $seconds, string $reason): void
    {
        $now = $this->now();
        $until = $now + $seconds;
        $block = $this->store->get(self::BLOCK_KEY.$policy);

        if ($seconds > 0 && $until > (float) ($block['until'] ?? 0)) {
            $this->store->put(self::BLOCK_KEY.$policy, ['until' => $until, 'reason' => $reason], $seconds);
            $this->recorded[$policy] = true;
        }
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
            $this->store->forget(self::HITS_KEY.$policy);
            $this->store->forget(self::BLOCK_KEY.$policy);
        }

        $this->recorded = [];
    }

    private function now(): float
    {
        return (float) ($this->clock)();
    }

    /**
     * Everything a check needs, read once: the current time, the restriction,
     * and for every window the times its counted hits age out, soonest first.
     *
     * @return array{0: float, 1: float, 2: string, 3: list<array{0: string, 1: RateLimitWindow, 2: list<float>}>}|null
     */
    private function state(string $policy): ?array
    {
        $entry = $this->store->get(self::POLICY_KEY.$policy);
        $state = $entry === null ? null : RateLimitPolicy::fromHeaders($entry['headers'] ?? []);

        if ($state === null) {
            return null;
        }

        $now = $this->now();
        $recordedAt = (float) ($entry['recorded_at'] ?? 0);
        $hits = $this->hitTimes($policy);

        // The restriction the latest response carries, and the stored one,
        // which a later-landing response cannot have overwritten.
        [$blockedUntil, $blockReason] = self::restriction($state, $recordedAt);
        $block = $this->store->get(self::BLOCK_KEY.$policy);

        if ($block !== null && (float) ($block['until'] ?? 0) > $blockedUntil) {
            $blockedUntil = (float) $block['until'];
            $blockReason = (string) ($block['reason'] ?? $blockReason);
        }

        $windows = [];

        foreach ($state->rules as $ruleName => $ruleWindows) {
            foreach ($ruleWindows as $window) {
                $windows[] = [$ruleName, $window, self::expiries($window, $hits, $recordedAt, $now)];
            }
        }

        return [$now, $blockedUntil, $blockReason, $windows];
    }

    /**
     * The times this window's counted hits age out, soonest first.
     *
     * Our own hits age out a period (plus EDGE_PAD_SECONDS) after they landed.
     * When GGG's count at the latest response exceeds the hits we recorded
     * inside that window, the difference ages out a period (plus the pad)
     * after that response.
     *
     * @param  list<float>  $hits  Landing times of every recorded response
     * @return list<float>
     */
    private static function expiries(RateLimitWindow $window, array $hits, float $recordedAt, float $now): array
    {
        $period = (float) $window->period;
        $held = $period + self::EDGE_PAD_SECONDS;
        $expiries = [];
        $recordedInWindow = 0;

        foreach ($hits as $at) {
            if ($at + $held > $now) {
                $expiries[] = $at + $held;
            }

            // Unpadded: a hit counted here lowers the unseen figure below, so
            // the narrower test is the one that never under-counts.
            if ($at <= $recordedAt && $at > $recordedAt - $period) {
                $recordedInWindow++;
            }
        }

        if ($recordedAt + $held > $now) {
            $unseen = max(0, $window->currentHits - $recordedInWindow);
            array_push($expiries, ...array_fill(0, $unseen, $recordedAt + $held));
        }

        sort($expiries);

        return $expiries;
    }

    /**
     * Seconds until this window has room for one more request.
     *
     * @param  list<float>  $expiries  Soonest first
     */
    private static function waitForOne(RateLimitWindow $window, array $expiries, float $safetyMargin, float $now): float
    {
        $allowed = $window->effectiveMaxHits($safetyMargin);
        $count = count($expiries);

        if ($count < $allowed) {
            return 0.0;
        }

        // A margin that leaves no hit at all: the window never has room, so
        // wait it out whole rather than report it free.
        if ($allowed < 1) {
            return $count === 0 ? (float) $window->period : end($expiries) - $now;
        }

        // Room for one more once all but ($allowed - 1) hits have aged out.
        return $expiries[$count - $allowed] - $now;
    }

    /**
     * When a restriction in this policy's headers ends, and why.
     *
     * @return array{0: float, 1: string}
     */
    private static function restriction(RateLimitPolicy $policy, float $recordedAt): array
    {
        $seconds = $policy->retryAfter ?? 0;
        $reason = $seconds > 0 ? 'Retry-After header active' : '';

        foreach ($policy->rules as $ruleName => $windows) {
            foreach ($windows as $window) {
                if ($window->activePenalty > $seconds) {
                    $seconds = $window->activePenalty;
                    $reason = "Rule '{$ruleName}' is penalized for {$window->activePenalty}s";
                }
            }
        }

        return [$recordedAt + $seconds, $reason];
    }

    /**
     * @return list<float>
     */
    private function hitTimes(string $policy): array
    {
        $at = $this->store->get(self::HITS_KEY.$policy)['at'] ?? [];

        return is_array($at) ? array_map('floatval', array_values($at)) : [];
    }

    private static function longestPeriod(RateLimitPolicy $policy): int
    {
        $longest = 0;

        foreach ($policy->rules as $windows) {
            foreach ($windows as $window) {
                $longest = max($longest, $window->period);
            }
        }

        return $longest;
    }

    /**
     * Whole seconds to wait, rounded up so the wait never ends early.
     *
     * The microsecond taken off first absorbs float noise in Unix timestamps
     * (a 6 s wait computed as 6.0000000002 s), which would otherwise add a
     * whole second; a microsecond is far below the request latency already
     * built into every hit time.
     */
    private static function seconds(float $wait): int
    {
        return max(0, (int) ceil($wait - 0.000001));
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
                $longest = max($longest, $window->period + self::EDGE_PAD_SECONDS, $window->activePenalty);
            }
        }

        return max(1, $longest);
    }
}
