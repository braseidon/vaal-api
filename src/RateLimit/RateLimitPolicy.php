<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * Parsed rate limit state for a single policy.
 *
 * Each GGG endpoint has a policy (e.g. "character-request-limit") with one
 * or more rules (account, client, ip). Each rule has multiple time windows
 * enforced simultaneously.
 *
 * Header format:
 *   X-Rate-Limit-Policy: character-request-limit
 *   X-Rate-Limit-Rules: Account
 *   X-Rate-Limit-Account: 5:10:60,30:300:300
 *   X-Rate-Limit-Account-State: 2:10:0,5:300:0
 */
class RateLimitPolicy
{
    /**
     * @param string                      $name       Policy name from X-Rate-Limit-Policy header
     * @param array<string, RateLimitWindow[]> $rules Rule name => windows
     * @param int|null                    $retryAfter Seconds from Retry-After header (429 only)
     */
    public function __construct(
        public readonly string $name,
        public readonly array  $rules      = [],
        public readonly ?int   $retryAfter = null,
    ) {}

    /**
     * Parse rate limit headers from an API response.
     *
     * Handles both raw arrays (where values may be arrays) and flattened
     * key-value pairs. GGG uses mixed case (X-Rate-Limit-Ip), so everything
     * is normalized to lowercase.
     *
     * @param array $headers Response headers
     * @return self|null Null if no rate limit headers are present
     */
    public static function fromHeaders(array $headers): ?self
    {
        $headers = self::normalizeHeaders($headers);

        $policy = $headers['x-rate-limit-policy'] ?? null;

        if ($policy === null) {
            return null;
        }

        $retryAfter = isset($headers['retry-after'])
            ? (int) $headers['retry-after']
            : null;

        $ruleNames = array_filter(
            array_map('trim', explode(',', $headers['x-rate-limit-rules'] ?? ''))
        );

        $rules = [];

        foreach ($ruleNames as $ruleName) {
            $key         = strtolower($ruleName);
            $limitHeader = $headers["x-rate-limit-{$key}"] ?? '';
            $stateHeader = $headers["x-rate-limit-{$key}-state"] ?? '';

            if ($limitHeader === '' || $stateHeader === '') {
                continue;
            }

            $rules[$key] = self::parseWindows($limitHeader, $stateHeader);
        }

        return new self($policy, $rules, $retryAfter);
    }

    /**
     * Parse limit and state headers into RateLimitWindow objects.
     *
     * Limit format:  "max_hits:period:penalty[,...]"
     * State format:  "current_hits:period:active_penalty[,...]"
     *
     * @param string $limitHeader The limit definition header
     * @param string $stateHeader The current state header
     * @return RateLimitWindow[]
     */
    private static function parseWindows(string $limitHeader, string $stateHeader): array
    {
        $limits  = explode(',', $limitHeader);
        $states  = explode(',', $stateHeader);
        $windows = [];

        foreach ($limits as $i => $limitSegment) {
            $limitParts = explode(':', $limitSegment);
            $stateParts = isset($states[$i]) ? explode(':', $states[$i]) : [];

            if (count($limitParts) !== 3) {
                continue;
            }

            $windows[] = new RateLimitWindow(
                maxHits:       (int) $limitParts[0],
                period:        (int) $limitParts[1],
                penalty:       (int) $limitParts[2],
                currentHits:   isset($stateParts[0]) ? (int) $stateParts[0] : 0,
                activePenalty: isset($stateParts[2]) ? (int) $stateParts[2] : 0,
            );
        }

        return $windows;
    }

    /**
     * Normalize headers to lowercase keys with string values.
     *
     * PSR-7 and Laravel's HTTP client return header values as arrays.
     * GGG's rate limit headers are single-valued, so we flatten safely.
     *
     * @param array $headers Raw headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = is_array($value)
                ? implode(',', $value)
                : (string) $value;
        }

        return $normalized;
    }
}
