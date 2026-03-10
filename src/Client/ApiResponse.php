<?php

namespace Braseidon\VaalApi\Client;

use Braseidon\VaalApi\RateLimit\RateLimitPolicy;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps a PSR-7 response with convenience methods for GGG API responses.
 */
class ApiResponse
{
    /** @var array|null Cached decoded JSON body */
    private ?array $decodedBody = null;

    /** @var RateLimitPolicy|null Cached rate limit policy */
    private ?RateLimitPolicy $rateLimitPolicy = null;

    /**
     * @param ResponseInterface $response The underlying PSR-7 response
     */
    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    /**
     * Decoded JSON response body.
     *
     * @return array
     */
    public function data(): array
    {
        if ($this->decodedBody === null) {
            $body              = (string) $this->response->getBody();
            $this->decodedBody = json_decode($body, true) ?? [];
        }

        return $this->decodedBody;
    }

    /**
     * HTTP status code.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Whether the response has a 2xx status code.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * Parsed rate limit policy from response headers.
     *
     * Returns null if no rate limit headers are present (e.g. /profile).
     *
     * @return RateLimitPolicy|null
     */
    public function rateLimitPolicy(): ?RateLimitPolicy
    {
        if ($this->rateLimitPolicy === null) {
            $this->rateLimitPolicy = RateLimitPolicy::fromHeaders(
                $this->response->getHeaders()
            );
        }

        return $this->rateLimitPolicy;
    }

    /**
     * Get a single response header value.
     *
     * @param string $name Header name
     * @return string|null
     */
    public function header(string $name): ?string
    {
        if (!$this->response->hasHeader($name)) {
            return null;
        }

        return $this->response->getHeaderLine($name);
    }

    /**
     * The underlying PSR-7 response.
     *
     * @return ResponseInterface
     */
    public function raw(): ResponseInterface
    {
        return $this->response;
    }
}
