<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * The default store: state kept in this object, visible only to the limiters
 * holding it. Expiry is left to RateLimiter, which ignores stale state anyway.
 */
class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }
}
