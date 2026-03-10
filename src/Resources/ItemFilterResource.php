<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\ItemFilter;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Item filter endpoints.
 *
 * Scope: account:item_filter
 *
 * @untested Endpoints not yet tested against the live API.
 */
class ItemFilterResource
{
    /**
     * @param ApiClient $client Authenticated API client
     */
    public function __construct(
        private readonly ApiClient $client,
    ) {}

    /**
     * List all item filters.
     *
     * @return ItemFilter[]
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::ItemFilter, 'ItemFilterResource');

        $response = $this->client->get('/item-filter');

        return array_map(
            fn (array $filter) => ItemFilter::fromArray($filter),
            $response->data()
        );
    }

    /**
     * Get a specific item filter.
     *
     * @param string $id Filter identifier
     * @return ItemFilter
     */
    public function get(string $id): ItemFilter
    {
        $this->client->requireScope(Scope::ItemFilter, 'ItemFilterResource');

        $response = $this->client->get('/item-filter/' . rawurlencode($id));

        return ItemFilter::fromArray($response->data());
    }

    /**
     * Create a new item filter.
     *
     * @param array{filter_name: string, realm: string, description?: string, version?: string, type?: string, public?: bool, filter: string} $data
     * @param bool $validate Whether to validate against the current game version
     * @return ItemFilter
     */
    public function create(array $data, bool $validate = false): ItemFilter
    {
        $this->client->requireScope(Scope::ItemFilter, 'ItemFilterResource');

        $query    = $validate ? ['validate' => 'true'] : [];
        $response = $this->client->post('/item-filter', $data, $query);

        return ItemFilter::fromArray($response->data());
    }

    /**
     * Update an existing item filter.
     *
     * All fields are optional for partial updates.
     * Note: Public filters cannot be made private again.
     *
     * @param string $id       Filter identifier
     * @param array  $data     Fields to update
     * @param bool   $validate Whether to validate against the current game version
     * @return ItemFilter
     */
    public function update(string $id, array $data, bool $validate = false): ItemFilter
    {
        $this->client->requireScope(Scope::ItemFilter, 'ItemFilterResource');

        $query    = $validate ? ['validate' => 'true'] : [];
        $response = $this->client->post('/item-filter/' . rawurlencode($id), $data, $query);

        return ItemFilter::fromArray($response->data());
    }
}
