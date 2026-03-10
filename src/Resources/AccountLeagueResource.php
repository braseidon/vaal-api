<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\League;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Account league endpoints.
 *
 * Scope: account:leagues
 *
 * Returns leagues available to the authenticated account, including
 * private leagues. The current league has category.current = true.
 */
class AccountLeagueResource
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
     * List leagues for the authenticated account.
     *
     * Rate limit: league-request-limit (5 req/10s, 10 req/60s)
     *
     * @return League[]
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::Leagues, 'AccountLeagueResource');

        $path = '/account/leagues';

        if ($this->realm !== null) {
            $path .= '/' . $this->realm->value;
        }

        $response = $this->client->get($path);

        return array_map(
            fn (array $league) => League::fromArray($league),
            $response->data()
        );
    }
}
