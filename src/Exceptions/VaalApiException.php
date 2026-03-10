<?php

namespace Braseidon\VaalApi\Exceptions;

use RuntimeException;

/**
 * Base exception for all Vaal API errors.
 */
class VaalApiException extends RuntimeException
{
    /**
     * @param string          $message      Error message
     * @param int             $code         HTTP status code or error code
     * @param \Throwable|null $previous     Previous exception
     * @param array           $responseBody Decoded API response body, if available
     */
    public function __construct(
        string     $message      = '',
        int        $code         = 0,
        ?\Throwable $previous    = null,
        protected array $responseBody = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The decoded response body from the API, if available.
     *
     * @return array
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
