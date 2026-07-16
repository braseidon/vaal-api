<?php

namespace Braseidon\VaalApi\Resources\Public;

use Braseidon\VaalApi\Client\ApiResponse;
use Braseidon\VaalApi\Exceptions\ConnectionException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;

/**
 * Client for GGG's public API endpoints (no OAuth required).
 *
 * Uses a different base URL (www.pathofexile.com) than the OAuth API.
 * Rate limits are IP-based rather than per-client.
 */
class PublicApiClient
{
    private GuzzleClient $httpClient;

    /**
     * @param array{
     *     client_id?: string,
     *     user_agent?: array{version?: string, contact?: string},
     *     public_url?: string,
     *     timeout?: int,
     *     connect_timeout?: int,
     * } $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {
        // Timeouts must stay well under PHP's max_execution_time (typically 30s)
        // so a hung GGG request raises a catchable ConnectionException instead
        // of a fatal error.
        $this->httpClient = new GuzzleClient([
            'base_uri'        => $this->config['public_url'] ?? 'https://www.pathofexile.com',
            'timeout'         => $this->config['timeout'] ?? 12,
            'connect_timeout' => $this->config['connect_timeout'] ?? 5,
            'http_errors'     => false,
            'headers'     => [
                'Accept'     => 'application/json',
                'User-Agent' => $this->buildUserAgent(),
            ],
        ]);
    }

    /**
     * Public league endpoints.
     *
     * @return PublicLeagueResource
     */
    public function leagues(): PublicLeagueResource
    {
        return new PublicLeagueResource($this);
    }

    /**
     * Public character endpoints for a specific account.
     *
     * @param string $accountName Account name
     * @return PublicCharacterResource
     */
    public function characters(string $accountName): PublicCharacterResource
    {
        return new PublicCharacterResource($this, $accountName);
    }

    /**
     * Public stash tabs endpoint (river-style).
     *
     * @return PublicStashTabResource
     */
    public function stashTabs(): PublicStashTabResource
    {
        return new PublicStashTabResource($this);
    }

    /**
     * Trade API endpoints.
     *
     * @return TradeResource
     */
    public function trade(): TradeResource
    {
        return new TradeResource($this);
    }

    /**
     * Make a GET request to the public API.
     *
     * @param string $path  API path
     * @param array  $query Query parameters
     * @return ApiResponse
     *
     * @throws VaalApiException
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        try {
            $response = new ApiResponse(
                $this->httpClient->request('GET', ltrim($path, '/'), ['query' => $query])
            );
        } catch (TransferException $e) {
            throw new ConnectionException(
                'GGG API did not respond: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!$response->isSuccessful()) {
            $this->handleError($response);
        }

        return $response;
    }

    /**
     * Make a POST request to the public API.
     *
     * @param string $path API path
     * @param array  $data JSON body data
     * @return ApiResponse
     *
     * @throws VaalApiException
     */
    public function post(string $path, array $data = []): ApiResponse
    {
        try {
            $response = new ApiResponse(
                $this->httpClient->request('POST', ltrim($path, '/'), ['json' => $data])
            );
        } catch (TransferException $e) {
            throw new ConnectionException(
                'GGG API did not respond: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!$response->isSuccessful()) {
            $this->handleError($response);
        }

        return $response;
    }

    /**
     * Handle non-2xx responses with appropriate exceptions.
     *
     * @param ApiResponse $response The API response
     * @return void
     *
     * @throws VaalApiException
     */
    private function handleError(ApiResponse $response): void
    {
        $status  = $response->status();
        $data    = $response->data();
        $message = $data['error']['message'] ?? $data['error'] ?? "HTTP {$status}";

        match (true) {
            $status === 404                 => throw new ResourceNotFoundException($message, $status, responseBody: $data),
            $status >= 400 && $status < 500 => throw new InvalidRequestException($message, $status, responseBody: $data),
            $status >= 500                  => throw new ServerException($message, $status, responseBody: $data),
            default                         => throw new VaalApiException($message, $status, responseBody: $data),
        };
    }

    /**
     * Build the User-Agent string for public API requests.
     *
     * Public endpoints omit the "OAuth" prefix since no auth is used.
     *
     * @return string
     */
    private function buildUserAgent(): string
    {
        $clientId = $this->config['client_id'] ?? 'unknown';
        $version  = $this->config['user_agent']['version'] ?? '1.0.0';
        $contact  = $this->config['user_agent']['contact'] ?? '';

        $ua = "{$clientId}/{$version}";

        if ($contact !== '') {
            $ua .= " (contact: {$contact})";
        }

        return $ua;
    }
}
