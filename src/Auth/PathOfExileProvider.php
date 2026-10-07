<?php

namespace Braseidon\VaalApi\Auth;

use GuzzleHttp\Exception\BadResponseException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * Path of Exile OAuth 2.1 provider for league/oauth2-client.
 *
 * GGG requires PKCE (S256) for all OAuth flows. Authorization codes
 * expire after 30 seconds.
 *
 * @see https://www.pathofexile.com/developer/docs
 */
class PathOfExileProvider extends AbstractProvider
{
    /**
     * Application version for User-Agent header.
     */
    protected string $userAgentVersion = '1.0.0';

    /**
     * Contact email for User-Agent header.
     */
    protected string $userAgentContact = '';

    /**
     * Authorization URL for GGG's OAuth flow.
     */
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://www.pathofexile.com/oauth/authorize';
    }

    /**
     * Token exchange URL.
     *
     * @param  array  $params  Token request parameters
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://www.pathofexile.com/oauth/token';
    }

    /**
     * Token revocation URL (RFC 7009).
     */
    public function getBaseRevokeTokenUrl(): string
    {
        return 'https://www.pathofexile.com/oauth/token/revoke';
    }

    /**
     * Revoke a token at GGG, so it stops working before it expires.
     *
     * GGG accepts this only from a client granted the `oauth:revoke` scope.
     * Revoking a refresh token per RFC 7009 also ends the grant behind it.
     *
     * @param  string  $token  The access or refresh token to revoke
     * @param  string|null  $tokenTypeHint  "access_token" or "refresh_token"
     *
     * @throws IdentityProviderException When GGG refuses the request
     */
    public function revokeToken(#[\SensitiveParameter] string $token, ?string $tokenTypeHint = null): void
    {
        $params = array_filter([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'token' => $token,
            'token_type_hint' => $tokenTypeHint,
        ], fn ($value) => $value !== null && $value !== '');

        $request = $this->createRequest('POST', $this->getBaseRevokeTokenUrl(), null, [
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        ]);

        try {
            $response = $this->getResponse($request);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
        }

        // A success has no body (RFC 7009 § 2.2), so only an error is parsed.
        if ($response->getStatusCode() >= 400) {
            $this->checkResponse($response, json_decode((string) $response->getBody(), true));
        }
    }

    /**
     * Resource owner details URL (profile endpoint).
     *
     * @param  AccessToken  $token  Access token
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
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * GGG requires PKCE with SHA-256 for all OAuth flows.
     */
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }

    /**
     * Build the User-Agent string per GGG requirements.
     *
     * Format: OAuth {client_id}/{version} (contact: {email})
     */
    protected function buildUserAgent(): string
    {
        $clientId = $this->clientId ?? 'unknown';

        return "OAuth {$clientId}/{$this->userAgentVersion} (contact: {$this->userAgentContact})";
    }

    /**
     * Allow passing headers to Guzzle's client constructor.
     *
     * This ensures our User-Agent overrides Guzzle's default (GuzzleHttp/7),
     * which Cloudflare blocks on GGG's endpoints.
     *
     * @param  array  $options  Provider constructor options
     * @return array<string>
     */
    protected function getAllowedClientOptions(array $options): array
    {
        return array_merge(parent::getAllowedClientOptions($options), ['headers']);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array  $options  Provider options including clientId, userAgentVersion, userAgentContact
     * @param  array  $collaborators  Collaborator overrides
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        // Inject our headers into options so Guzzle picks them up via getAllowedClientOptions
        $options['headers'] = $options['headers'] ?? [];
        $options['headers']['User-Agent'] = $options['headers']['User-Agent']
            ?? 'OAuth '.($options['clientId'] ?? 'unknown').'/'.($options['userAgentVersion'] ?? '1.0.0').' (contact: '.($options['userAgentContact'] ?? '').')';
        $options['headers']['Accept'] = $options['headers']['Accept'] ?? 'application/json';

        parent::__construct($options, $collaborators);
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
            'User-Agent' => $this->buildUserAgent(),
        ];
    }

    /**
     * Authorization headers for API requests.
     *
     * @param  mixed  $token  Access token string or null
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        $headers = [];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    /**
     * Validate the response and throw on errors.
     *
     * @param  ResponseInterface  $response  The HTTP response
     * @param  mixed  $data  Decoded response body
     *
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $message = is_array($data)
                ? $data['error_description'] ?? $data['error'] ?? $data['message'] ?? 'Unknown error'
                : 'Unknown error';

            throw new IdentityProviderException(
                is_string($message) ? $message : (json_encode($message) ?: 'Unknown error'),
                $statusCode,
                $data,
            );
        }
    }

    /**
     * Create a resource owner from the profile response.
     *
     * @param  array  $response  Profile response data
     * @param  AccessToken  $token  Access token
     */
    protected function createResourceOwner(array $response, AccessToken $token): PathOfExileResourceOwner
    {
        return new PathOfExileResourceOwner($response);
    }
}
