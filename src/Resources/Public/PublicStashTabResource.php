<?php

namespace Braseidon\VaalApi\Resources\Public;

use Braseidon\VaalApi\Enums\Realm;

/**
 * Public stash tabs endpoint (no auth, river-style).
 *
 * Rate limit: ~1 request per second (IP-based).
 * Data has a 5-minute delay from real time.
 *
 * @untested Endpoint not yet tested against the live API.
 */
class PublicStashTabResource
{
    /**
     * @param PublicApiClient $client Public API client
     */
    public function __construct(
        private readonly PublicApiClient $client,
    ) {}

    /**
     * Get public stash tabs.
     *
     * @param string|null $changeId Pagination ID from previous response
     * @param Realm|null  $realm    Optional realm filter
     * @return array{stashes: array, next_change_id: string}
     */
    public function get(?string $changeId = null, ?Realm $realm = null): array
    {
        $path = '/api/public-stash-tabs';

        if ($realm !== null) {
            $path .= '/' . $realm->value;
        }

        $query    = $changeId !== null ? ['id' => $changeId] : [];
        $response = $this->client->get($path, $query);

        return $response->data();
    }
}
