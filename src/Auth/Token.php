<?php

namespace Braseidon\VaalApi\Auth;

use Braseidon\VaalApi\Enums\Scope;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Immutable token representing a GGG OAuth session.
 *
 * GGG's token response includes non-standard fields (username, sub)
 * that are captured here for convenience.
 */
readonly class Token
{
    /**
     * @param string      $accessToken  The OAuth access token
     * @param string      $refreshToken The OAuth refresh token (single-use)
     * @param int         $expiresAt    Unix timestamp when the access token expires
     * @param string      $scope        Space-separated list of granted scopes
     * @param string|null $username     GGG display name with discriminator (e.g. "Player#1234")
     * @param string|null $sub          GGG account UUID (stable identifier)
     */
    public function __construct(
        public string  $accessToken,
        public string  $refreshToken,
        public int     $expiresAt,
        public string  $scope,
        public ?string $username = null,
        public ?string $sub      = null,
    ) {}

    /**
     * Whether the access token has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    /**
     * Whether the token should be refreshed soon.
     *
     * Returns true if the token will expire within the buffer window,
     * allowing proactive refresh before requests fail.
     *
     * @param int $bufferSeconds Seconds before expiry to trigger refresh
     * @return bool
     */
    public function needsRefresh(int $bufferSeconds = 300): bool
    {
        return time() >= ($this->expiresAt - $bufferSeconds);
    }

    /**
     * Whether this token has the given scope.
     *
     * @param Scope|string $scope A Scope enum or scope string (e.g. "account:characters")
     * @return bool
     */
    public function hasScope(Scope|string $scope): bool
    {
        $scopeValue = $scope instanceof Scope ? $scope->value : $scope;
        $granted    = explode(' ', $this->scope);

        return in_array($scopeValue, $granted, true);
    }

    /**
     * Create from a League OAuth2 AccessToken.
     *
     * GGG includes `username` and `sub` (account UUID) in token responses.
     * League stores these in AccessToken::$values since they're non-standard.
     *
     * @param AccessToken $accessToken The League OAuth2 access token
     * @return self
     */
    public static function fromAccessToken(AccessToken $accessToken): self
    {
        $values = $accessToken->getValues();

        return new self(
            accessToken:  $accessToken->getToken(),
            refreshToken: $accessToken->getRefreshToken() ?? '',
            expiresAt:    $accessToken->getExpires() ?? 0,
            scope:        $values['scope'] ?? '',
            username:     $values['username'] ?? null,
            sub:          $values['sub'] ?? null,
        );
    }

    /**
     * Create from a stored array (database, session, etc).
     *
     * @param array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_at?: int,
     *     scope?: string,
     *     username?: string|null,
     *     sub?: string|null,
     * } $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken:  $data['access_token'],
            refreshToken: $data['refresh_token'] ?? '',
            expiresAt:    $data['expires_at'] ?? 0,
            scope:        $data['scope'] ?? '',
            username:     $data['username'] ?? null,
            sub:          $data['sub'] ?? null,
        );
    }

    /**
     * Serialize to array for storage.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_at: int,
     *     scope: string,
     *     username: string|null,
     *     sub: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at'    => $this->expiresAt,
            'scope'         => $this->scope,
            'username'      => $this->username,
            'sub'           => $this->sub,
        ];
    }
}
