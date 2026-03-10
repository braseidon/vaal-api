<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\League;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Service league endpoints.
 *
 * Scope: service:leagues (list, get), service:leagues:ladder (ladder, event-ladder)
 *
 * @untested Endpoints not yet tested against the live API.
 */
class LeagueResource
{
    /**
     * @param ApiClient $client Authenticated API client
     */
    public function __construct(
        private readonly ApiClient $client,
    ) {}

    /**
     * List all leagues.
     *
     * @param array{realm?: string, type?: string, season?: string, limit?: int, offset?: int} $params
     * @return League[]
     */
    public function list(array $params = []): array
    {
        $this->client->requireScope(Scope::ServiceLeagues, 'LeagueResource');

        $response = $this->client->get('/league', $params);

        return array_map(
            fn (array $league) => League::fromArray($league),
            $response->data()
        );
    }

    /**
     * Get a specific league by ID.
     *
     * @param string $leagueId League identifier
     * @return League
     */
    public function get(string $leagueId): League
    {
        $this->client->requireScope(Scope::ServiceLeagues, 'LeagueResource');

        $response = $this->client->get('/league/' . rawurlencode($leagueId));

        return League::fromArray($response->data());
    }

    /**
     * Get the ladder for a league (PoE1 only).
     *
     * Scope: service:leagues:ladder
     *
     * @param string $leagueId League identifier
     * @param array{sort?: string, class?: string, limit?: int, offset?: int} $params
     * @return array
     */
    public function ladder(string $leagueId, array $params = []): array
    {
        $this->client->requireScope(Scope::ServiceLeaguesLadder, 'LeagueResource::ladder');

        $path     = '/league/' . rawurlencode($leagueId) . '/ladder';
        $response = $this->client->get($path, $params);

        return $response->data();
    }

    /**
     * Get the event ladder for a league (PoE1 only).
     *
     * Scope: service:leagues:ladder
     *
     * @param string $leagueId League identifier
     * @return array
     */
    public function eventLadder(string $leagueId): array
    {
        $this->client->requireScope(Scope::ServiceLeaguesLadder, 'LeagueResource::eventLadder');

        $path     = '/league/' . rawurlencode($leagueId) . '/event-ladder';
        $response = $this->client->get($path);

        return $response->data();
    }
}
