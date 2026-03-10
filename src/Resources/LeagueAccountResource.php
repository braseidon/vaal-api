<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * League account endpoints (PoE1 only).
 *
 * Scope: account:league_accounts
 *
 * Returns atlas passives and atlas passive trees for a league.
 *
 * @untested Endpoint not yet tested against the live API.
 */
class LeagueAccountResource
{
    /**
     * @param ApiClient  $client Authenticated API client
     * @param string     $league League name
     * @param Realm|null $realm  Game realm (null defaults to PC)
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly string $league,
        private readonly ?Realm $realm = null,
    ) {}

    /**
     * Get the account's league data (atlas passives).
     *
     * @return array
     */
    public function get(): array
    {
        $this->client->requireScope(Scope::LeagueAccounts, 'LeagueAccountResource');

        $path = '/league-account';

        if ($this->realm !== null) {
            $path .= '/' . $this->realm->value;
        }

        $path .= '/' . rawurlencode($this->league);

        $response = $this->client->get($path);

        return $response->data();
    }
}
