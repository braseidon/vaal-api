<?php

namespace Braseidon\VaalApi;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;

/**
 * Static entry point for the Path of Exile API.
 *
 * Usage:
 *   $api = VaalApi::for($token, $config);
 *   $api->characters()->list();
 *
 *   $public = VaalApi::public($config);
 *   $public->leagues()->list();
 */
class VaalApi
{
    /**
     * Create an authenticated API client.
     *
     * @param Token $token  OAuth token for authenticated requests
     * @param array $config Client configuration (see ApiClient for options)
     * @return ApiClient
     */
    public static function for(Token $token, array $config = []): ApiClient
    {
        $client = new ApiClient($config);
        $client->withToken($token);

        return $client;
    }

    /**
     * Create a public API client (no authentication required).
     *
     * @param array $config Client configuration
     * @return PublicApiClient
     */
    public static function public(array $config = []): PublicApiClient
    {
        return new PublicApiClient($config);
    }
}
