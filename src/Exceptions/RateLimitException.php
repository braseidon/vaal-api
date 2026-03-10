<?php

namespace Braseidon\VaalApi\Exceptions;

use Braseidon\VaalApi\RateLimit\RateLimitResult;

/**
 * Thrown when a rate limit is hit or about to be exceeded.
 *
 * Contains the RateLimitResult with policy details and retry timing.
 */
class RateLimitException extends VaalApiException
{
    /**
     * @param RateLimitResult $rateLimitResult Rate limit details
     * @param string          $message         Error message (auto-generated if empty)
     * @param int             $code            HTTP status code
     * @param \Throwable|null $previous        Previous exception
     * @param array           $responseBody    Decoded API response body, if available
     */
    public function __construct(
        protected RateLimitResult $rateLimitResult,
        string      $message      = '',
        int         $code         = 429,
        ?\Throwable $previous     = null,
        array       $responseBody = [],
    ) {
        if ($message === '') {
            $message = sprintf(
                'Rate limited on policy "%s". Retry after %d seconds.',
                $rateLimitResult->policy,
                $rateLimitResult->waitSeconds,
            );
        }

        parent::__construct($message, $code, $previous, $responseBody);
    }

    /**
     * Get the rate limit details.
     *
     * @return RateLimitResult
     */
    public function getRateLimitResult(): RateLimitResult
    {
        return $this->rateLimitResult;
    }

    /**
     * Seconds to wait before retrying.
     *
     * @return int
     */
    public function getRetryAfter(): int
    {
        return $this->rateLimitResult->waitSeconds;
    }
}
