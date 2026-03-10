<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\StashTab;
use Braseidon\VaalApi\Dto\StashTabSummary;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Guild stash tab endpoints.
 *
 * @untested Endpoints not yet tested against the live API.
 */
class GuildStashResource
{
    /**
     * @param ApiClient  $client Authenticated API client
     * @param string     $league League name
     * @param Realm|null $realm  Game realm (null defaults to PC)
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly string $league,
        private readonly ?Realm $realm = null,
    ) {}

    /**
     * List guild stash tabs.
     *
     * @return StashTabSummary[]
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::GuildStashes, 'GuildStashResource');

        $path     = $this->buildPath() . '/' . rawurlencode($this->league);
        $response = $this->client->get($path);

        return array_map(
            fn (array $tab) => StashTabSummary::fromArray($tab),
            $response->data()
        );
    }

    /**
     * Get a guild stash tab with items.
     *
     * @param string      $stashId    Stash tab identifier
     * @param string|null $substashId Optional substash ID for nested tabs
     * @return StashTab
     */
    public function get(string $stashId, ?string $substashId = null): StashTab
    {
        $this->client->requireScope(Scope::GuildStashes, 'GuildStashResource');

        $path = $this->buildPath()
            . '/' . rawurlencode($this->league)
            . '/' . rawurlencode($stashId);

        if ($substashId !== null) {
            $path .= '/' . rawurlencode($substashId);
        }

        $response = $this->client->get($path);

        return StashTab::fromArray($response->data());
    }

    /**
     * Build the guild stash base path with optional realm.
     *
     * @return string
     */
    private function buildPath(): string
    {
        $path = '/guild';

        if ($this->realm !== null) {
            $path .= '/' . $this->realm->value;
        }

        return $path . '/stash';
    }
}
