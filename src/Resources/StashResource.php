<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\StashTab;
use Braseidon\VaalApi\Dto\StashTabSummary;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;

/**
 * Stash tab endpoints (PoE1 only).
 *
 * Scope: account:stashes
 */
class StashResource
{
    /**
     * @param ApiClient  $client Authenticated API client
     * @param string     $league League name (e.g. "Standard", "Mirage")
     * @param Realm|null $realm  Game realm (null defaults to PC)
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly string $league,
        private readonly ?Realm $realm = null,
    ) {}

    /**
     * List all stash tabs for a league.
     *
     * Rate limit: stash-list-request-limit (10 req/15s, 30 req/60s)
     * Response size: 17KB (active league) to 182KB (Standard with many remove-only tabs)
     *
     * @return StashTabSummary[]
     */
    public function list(): array
    {
        $this->client->requireScope(Scope::Stashes, 'StashResource');

        $path     = $this->buildPath('/stash') . '/' . rawurlencode($this->league);
        $response = $this->client->get($path);

        return array_map(
            fn (array $tab) => StashTabSummary::fromArray($tab),
            $response->data()
        );
    }

    /**
     * Get a stash tab with all its items.
     *
     * Rate limit: stash-request-limit (15 req/10s, 30 req/5min)
     * Response size: ~207KB per tab.
     *
     * @param string      $stashId    10-character hex stash ID
     * @param string|null $substashId Optional substash ID for nested tabs
     * @return StashTab
     */
    public function get(string $stashId, ?string $substashId = null): StashTab
    {
        $this->client->requireScope(Scope::Stashes, 'StashResource');

        $path = $this->buildPath('/stash')
            . '/' . rawurlencode($this->league)
            . '/' . rawurlencode($stashId);

        if ($substashId !== null) {
            $path .= '/' . rawurlencode($substashId);
        }

        $response = $this->client->get($path);

        return StashTab::fromArray($response->data());
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
