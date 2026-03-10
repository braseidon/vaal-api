<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\Profile;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Account profile endpoint.
 *
 * Scope: account:profile
 */
class ProfileResource
{
    /**
     * @param ApiClient $client Authenticated API client
     */
    public function __construct(
        private readonly ApiClient $client,
    ) {}

    /**
     * Get the authenticated account's profile.
     *
     * Returns the account UUID, display name, locale, and optional
     * Twitch/guild connections.
     *
     * @return Profile
     */
    public function get(): Profile
    {
        $this->client->requireScope(Scope::Profile, 'ProfileResource');

        $response = $this->client->get('/profile');

        return Profile::fromArray($response->data());
    }
}
