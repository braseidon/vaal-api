<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Currency exchange endpoint.
 *
 * Scope: service:cxapi
 *
 * @untested Endpoint not yet tested against the live API.
 */
class CurrencyExchangeResource
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
     * Get currency exchange rates.
     *
     * @param string|null $currencyId Specific currency, or null for all
     * @return array
     */
    public function get(?string $currencyId = null): array
    {
        $this->client->requireScope(Scope::ServiceCxapi, 'CurrencyExchangeResource');

        $path = '/currency-exchange';

        if ($this->realm !== null) {
            $path .= '/' . $this->realm->value;
        }

        if ($currencyId !== null) {
            $path .= '/' . rawurlencode($currencyId);
        }

        $response = $this->client->get($path);

        return $response->data();
    }
}
