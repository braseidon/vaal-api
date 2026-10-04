<?php

namespace Braseidon\VaalApi\Client;

use Braseidon\VaalApi\Exceptions\VaalApiException;

/**
 * What a batch of requests returned, keyed as the caller keyed the batch.
 *
 * Every key the caller passed lands in exactly one of the two arrays, in the
 * order the caller passed them. A failure is the exception a single request
 * would have thrown for that key (404, 401/403, a 429 the batch gave up on, a
 * connection failure, ...).
 *
 * @template T
 */
readonly class BatchResult
{
    /**
     * @param  array<array-key, T>  $results  Successful responses, as the caller's type
     * @param  array<array-key, VaalApiException>  $failures  Per-key failures
     */
    public function __construct(
        public array $results,
        public array $failures,
    ) {}

    /**
     * Whether every key succeeded.
     */
    public function succeeded(): bool
    {
        return $this->failures === [];
    }
}
