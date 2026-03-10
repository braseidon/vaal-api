<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Public stash tabs endpoint (OAuth version).
 *
 * Scope: service:psapi
 *
 * Returns public stash tabs with a 5-minute data delay.
 * Use the `next_change_id` from the response to paginate.
 *
 * @untested Endpoint not yet tested against the live API.
 */
class PublicStashTabResource
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
     * Get public stash tabs.
     *
     * @param string|null $changeId Pagination ID. Omit for the first request.
     * @return array{stashes: array, next_change_id: string}
     */
    public function get(?string $changeId = null): array
    {
        $this->client->requireScope(Scope::ServicePsapi, 'PublicStashTabResource');

        $path = '/public-stash-tabs';

        if ($this->realm !== null) {
            $path .= '/' . $this->realm->value;
        }

        $query    = $changeId !== null ? ['id' => $changeId] : [];
        $response = $this->client->get($path, $query);

        return $response->data();
    }
}
