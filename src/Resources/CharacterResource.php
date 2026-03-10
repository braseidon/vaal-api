<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\Character;
use Braseidon\VaalApi\Dto\CharacterSummary;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Character endpoints.
 *
 * Scope: account:characters
 *
 * The character list endpoint has the tightest rate limit of all
 * GGG endpoints (2 requests per 10 seconds). Cache aggressively.
 */
class CharacterResource
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
     * List all characters on the account.
     *
     * Rate limit: character-list-request-limit (2 req/10s, 5 req/5min)
     *
     * @return CharacterSummary[]
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::Characters, 'CharacterResource');

        $path     = $this->buildPath('/character');
        $response = $this->client->get($path);

        $characters = $response->data()['characters'] ?? $response->data();

        return array_map(
            fn (array $char) => CharacterSummary::fromArray($char),
            $characters
        );
    }

    /**
     * Get full character data including equipment, passives, and jewels.
     *
     * Rate limit: character-request-limit (5 req/10s, 30 req/5min)
     * Response size: 200-320KB per character.
     *
     * @param string $name Character name
     * @return Character
     */
    public function get(string $name): Character
    {
        $this->client->requireScope(Scope::Characters, 'CharacterResource');

        $path     = $this->buildPath('/character') . '/' . rawurlencode($name);
        $response = $this->client->get($path);

        return Character::fromArray($response->data());
    }

    /**
     * Build a path with optional realm segment.
     *
     * @param string $base Base path
     * @return string
     */
    private function buildPath(string $base): string
    {
        if ($this->realm !== null) {
            return $base . '/' . $this->realm->value;
        }

        return $base;
    }
}
