<?php

namespace Braseidon\VaalApi\Resources\Public;

/**
 * Public character endpoints (legacy character-window API).
 *
 * Only works for accounts with public profiles. Rate limited by IP.
 * Three separate calls are needed for full character data (unlike the
 * OAuth API which returns everything in one call).
 *
 * @untested Endpoints not yet tested against the live API.
 */
class PublicCharacterResource
{
    /**
     * @param PublicApiClient $client      Public API client
     * @param string          $accountName Account name to query
     */
    public function __construct(
        private readonly PublicApiClient $client,
        private readonly string $accountName,
    ) {}

    /**
     * List all characters for the account.
     *
     * @param string|null $realm Optional realm filter
     * @return array
     */
    public function list(?string $realm = null): array
    {
        $query = ['accountName' => $this->accountName];

        if ($realm !== null) {
            $query['realm'] = $realm;
        }

        $response = $this->client->get('/character-window/get-characters', $query);

        return $response->data();
    }

    /**
     * Get passive skill allocations for a character.
     *
     * @param string      $characterName Character name
     * @param string|null $realm         Optional realm filter
     * @return array
     */
    public function passives(string $characterName, ?string $realm = null): array
    {
        $query = [
            'accountName' => $this->accountName,
            'character'   => $characterName,
        ];

        if ($realm !== null) {
            $query['realm'] = $realm;
        }

        $response = $this->client->get('/character-window/get-passive-skills', $query);

        return $response->data();
    }

    /**
     * Get equipped items for a character.
     *
     * @param string      $characterName Character name
     * @param string|null $realm         Optional realm filter
     * @return array
     */
    public function items(string $characterName, ?string $realm = null): array
    {
        $query = [
            'accountName' => $this->accountName,
            'character'   => $characterName,
        ];

        if ($realm !== null) {
            $query['realm'] = $realm;
        }

        $response = $this->client->get('/character-window/get-items', $query);

        return $response->data();
    }
}
