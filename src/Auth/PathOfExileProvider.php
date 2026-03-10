<?php

namespace Braseidon\VaalApi\Auth;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * Path of Exile OAuth 2.0 provider for league/oauth2-client.
 *
 * GGG requires PKCE (S256) for all OAuth flows. Authorization codes
 * expire after 30 seconds.
 *
 * @see https://www.pathofexile.com/developer/docs
 */
class PathOfExileProvider extends AbstractProvider
{
    /**
     * Authorization URL for GGG's OAuth flow.
     *
     * @return string
     */
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://www.pathofexile.com/oauth/authorize';
    }

    /**
     * Token exchange URL.
     *
     * @param array $params Token request parameters
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://www.pathofexile.com/oauth/token';
    }

    /**
     * Resource owner details URL (profile endpoint).
     *
     * @param AccessToken $token Access token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return 'https://api.pathofexile.com/profile';
    }

    /**
     * Default scopes - empty, configured per-application.
     *
     * @return string[]
     */
    protected function getDefaultScopes(): array
    {
        return [];
    }

    /**
     * Scope separator - GGG uses spaces.
     *
     * @return string
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * GGG requires PKCE with SHA-256 for all OAuth flows.
     *
     * @return string
     */
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }

    /**
     * Default request headers.
     *
     * @return array<string, string>
     */
    protected function getDefaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    /**
     * Authorization headers for API requests.
     *
     * @param mixed $token Access token string or null
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        $headers = [];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Validate the response and throw on errors.
     *
     * @param ResponseInterface $response The HTTP response
     * @param mixed             $data     Decoded response body
     * @return void
     *
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $message = $data['error_description']
                ?? $data['error']
                ?? $data['message']
                ?? 'Unknown error';

            throw new IdentityProviderException(
                $message,
                $statusCode,
                $data,
            );
        }
    }

    /**
     * Create a resource owner from the profile response.
     *
     * @param array       $response Profile response data
     * @param AccessToken $token    Access token
     * @return PathOfExileResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token): PathOfExileResourceOwner
    {
        return new PathOfExileResourceOwner($response);
    }
}
