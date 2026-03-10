<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;

/**
 * Guild endpoints.
 *
 * Scope: account:guild:stashes (requires special request to GGG)
 *
 * @untested Endpoints not yet tested against the live API.
 */
class GuildResource
{
    /**
     * @param ApiClient  $client Authenticated API client
     * @param Realm|null $realm  Game realm (null defaults to PC)
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly ?Realm $realm = null,
    ) {}

    /**
     * Get a stash resource for guild stash tabs.
     *
     * @param string $league League name
     * @return GuildStashResource
     */
    public function stashes(string $league): GuildStashResource
    {
        return new GuildStashResource($this->client, $league, $this->realm);
    }
}
