<?php

namespace Braseidon\VaalApi\Enums;

/**
 * Strategies for handling rate limit violations.
 *
 * Configured on the ApiClient. The RateLimiter itself is side-effect-free
 * and only reports state.
 */
enum RateLimitStrategy: string
{
    /** Wait the required duration and retry automatically. */
    case Sleep     = 'sleep';

    /** Throw a RateLimitException with retry details. */
    case Exception = 'exception';

    /** Call a user-provided closure with the RateLimitResult. */
    case Callback  = 'callback';

    /** Log a PSR-3 warning and continue without waiting. */
    case Log       = 'log';
}
