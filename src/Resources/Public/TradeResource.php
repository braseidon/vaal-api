<?php

namespace Braseidon\VaalApi\Resources\Public;

use Braseidon\VaalApi\Dto\TradeSearchResult;

/**
 * Trade API endpoints.
 *
 * Trade uses a two-step process: search returns result hashes,
 * then fetch retrieves the actual item data. Reference data
 * endpoints (items, stats, static) are CDN-cached with no rate limits.
 *
 * Rate limits are IP-based, tracked via response headers.
 *
 * @untested Endpoints not yet tested against the live API.
 */
class TradeResource
{
    /**
     * @param PublicApiClient $client Public API client
     */
    public function __construct(
        private readonly PublicApiClient $client,
    ) {}

    /**
     * Search for items on the trade site.
     *
     * @param string $league League name (e.g. "Standard", "Mirage")
     * @param array  $query  Trade search query
     * @return TradeSearchResult
     */
    public function search(string $league, array $query): TradeSearchResult
    {
        $path     = '/api/trade/search/' . rawurlencode($league);
        $response = $this->client->post($path, $query);

        return TradeSearchResult::fromArray($response->data());
    }

    /**
     * Fetch item details by result hashes from a search.
     *
     * @param string   $searchId The search ID from a previous search
     * @param string[] $itemIds  Result hashes to fetch (max 10 per request)
     * @return array
     */
    public function fetch(string $searchId, array $itemIds): array
    {
        $path     = '/api/trade/fetch/' . implode(',', array_map('rawurlencode', $itemIds));
        $response = $this->client->get($path, ['query' => $searchId]);

        return $response->data();
    }

    /**
     * Get all tradeable item types and uniques.
     *
     * CDN-cached, no rate limit.
     *
     * @return array
     */
    public function items(): array
    {
        $response = $this->client->get('/api/trade/data/items');

        return $response->data();
    }

    /**
     * Get all filterable stats for trade searches.
     *
     * CDN-cached, no rate limit.
     *
     * @return array
     */
    public function stats(): array
    {
        $response = $this->client->get('/api/trade/data/stats');

        return $response->data();
    }

    /**
     * Get static reference data (currencies, fragments, etc).
     *
     * CDN-cached, no rate limit.
     *
     * @return array
     */
    public function static(): array
    {
        $response = $this->client->get('/api/trade/data/static');

        return $response->data();
    }
}
