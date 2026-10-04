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
     * @param  ApiClient  $client  Authenticated API client
     */
    public function __construct(
        private readonly ApiClient $client,
    ) {}

    /**
     * List all leagues.
     *
     * @param  array{realm?: string, type?: string, season?: string, limit?: int, offset?: int}  $params
     * @return League[]
     */
    public function list(array $params = []): array
    {
        $this->client->requireScope(Scope::ServiceLeagues, 'LeagueResource');

        $data = $this->client->get('/league', $params)->data();

        // GGG wraps the list: {"leagues": [...]}
        if (! isset($data['leagues']) || ! is_array($data['leagues'])) {
            throw new \UnexpectedValueException('GET /league returned no "leagues" list.');
        }

        return array_map(
            fn (array $league) => League::fromArray($league),
            $data['leagues']
        );
    }

    /**
     * Get a specific league by ID.
     *
     * @param  string  $leagueId  League identifier
     * @return League|null Null when GGG answers {"league": null} (no such league)
     *
     * @throws \UnexpectedValueException When the response has no "league" key
     */
    public function get(string $leagueId): ?League
    {
        $this->client->requireScope(Scope::ServiceLeagues, 'LeagueResource');

        $path = '/league/'.rawurlencode($leagueId);
        $data = $this->client->get($path)->data();

        if (! array_key_exists('league', $data) || ! (is_array($data['league']) || $data['league'] === null)) {
            throw new \UnexpectedValueException('GET '.$path.' returned no "league" key.');
        }

        return $data['league'] === null ? null : League::fromArray($data['league']);
    }

    /**
     * Get the ladder for a league (PoE1 only).
     *
     * Scope: service:leagues:ladder
     *
     * @param  string  $leagueId  League identifier
     * @param  array{sort?: string, class?: string, limit?: int, offset?: int}  $params
     */
    public function ladder(string $leagueId, array $params = []): array
    {
        $this->client->requireScope(Scope::ServiceLeaguesLadder, 'LeagueResource::ladder');

        $path = '/league/'.rawurlencode($leagueId).'/ladder';
        $response = $this->client->get($path, $params);

        return $response->data();
    }

    /**
     * Get the event ladder for a league (PoE1 only).
     *
     * Scope: service:leagues:ladder
     *
     * @param  string  $leagueId  League identifier
     */
    public function eventLadder(string $leagueId): array
    {
        $this->client->requireScope(Scope::ServiceLeaguesLadder, 'LeagueResource::eventLadder');

        $path = '/league/'.rawurlencode($leagueId).'/event-ladder';
        $response = $this->client->get($path);

        return $response->data();
    }
}
