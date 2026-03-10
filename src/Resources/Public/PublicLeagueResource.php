<?php

namespace Braseidon\VaalApi\Resources\Public;

use Braseidon\VaalApi\Dto\League;

/**
 * Public league endpoint (no auth required).
 *
 * @untested Endpoint not yet tested against the live API.
 */
class PublicLeagueResource
{
    /**
     * @param PublicApiClient $client Public API client
     */
    public function __construct(
        private readonly PublicApiClient $client,
    ) {}

    /**
     * List all public leagues.
     *
     * @param array{type?: string, season?: string, limit?: int, offset?: int} $params
     * @return League[]
     */
    public function list(array $params = []): array
    {
        $response = $this->client->get('/api/leagues', $params);

        return array_map(
            fn (array $league) => League::fromArray($league),
            $response->data()
        );
    }
}
