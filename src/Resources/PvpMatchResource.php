<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;

/**
 * PvP match endpoints (PoE1 only).
 *
 * Scope: service:pvp_matches (list, get), service:pvp_matches:ladder (ladder)
 *
 * @untested Endpoints not yet tested against the live API.
 */
class PvpMatchResource
{
    /**
     * @param ApiClient $client Authenticated API client
     */
    public function __construct(
        private readonly ApiClient $client,
    ) {}

    /**
     * List PvP matches.
     *
     * @return array
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::ServicePvpMatches, 'PvpMatchResource');

        $response = $this->client->get('/pvp-match');

        return $response->data();
    }

    /**
     * Get a specific PvP match.
     *
     * @param string $matchId Match identifier
     * @return array
     */
    public function get(string $matchId): array
    {
        $this->client->requireScope(Scope::ServicePvpMatches, 'PvpMatchResource');

        $response = $this->client->get('/pvp-match/' . rawurlencode($matchId));

        return $response->data();
    }

    /**
     * Get the ladder for a PvP match.
     *
     * Scope: service:pvp_matches:ladder
     *
     * @param string $matchId Match identifier
     * @return array
     */
    public function ladder(string $matchId): array
    {
        $this->client->requireScope(Scope::ServicePvpMatchesLadder, 'PvpMatchResource::ladder');

        $path     = '/pvp-match/' . rawurlencode($matchId) . '/ladder';
        $response = $this->client->get($path);

        return $response->data();
    }
}
