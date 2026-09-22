<?php

namespace Braseidon\VaalApi\RateLimit;

/**
 * Where a RateLimiter keeps what it learned from GGG's response headers.
 *
 * The default store lives and dies with one limiter. An app that builds a
 * client per web request or per queue job passes a store every process can
 * read (Redis, a shared cache), so a lockout one process ran into stops the
 * others before they send. Scope the keys per account: GGG counts account
 * rules per account.
 */
interface RateLimitStore
{
    /**
     * @return array<string, mixed>|null Null when the key is absent or expired
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $value
     * @param  int  $ttlSeconds  How long the value stays meaningful
     */
    public function put(string $key, array $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
