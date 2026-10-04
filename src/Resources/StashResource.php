<?php

namespace Braseidon\VaalApi\Resources;

use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Client\ApiResponse;
use Braseidon\VaalApi\Client\BatchResult;
use Braseidon\VaalApi\Dto\StashTab;
use Braseidon\VaalApi\Dto\StashTabSummary;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Closure;

/**
 * Stash tab endpoints (PoE1 only).
 *
 * Scope: account:stashes
 */
class StashResource
{
    /**
     * @param  ApiClient  $client  Authenticated API client
     * @param  string  $league  League name (e.g. "Standard", "Mirage")
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
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

        $path = $this->buildPath('/stash').'/'.rawurlencode($this->league);
        $response = $this->client->get($path);

        return array_map(
            fn (array $tab) => StashTabSummary::fromArray($tab),
            $response->data()['stashes'] ?? []
        );
    }

    /**
     * Get a stash tab with all its items.
     *
     * Rate limit: stash-request-limit (15 req/10s, 30 req/5min)
     * Response size: ~207KB per tab.
     *
     * @param  string  $stashId  10-character hex stash ID
     * @param  string|null  $substashId  Optional substash ID for nested tabs
     */
    public function get(string $stashId, ?string $substashId = null): StashTab
    {
        $this->client->requireScope(Scope::Stashes, 'StashResource');

        return self::tab($this->client->get($this->tabPath($stashId, $substashId)));
    }

    /**
     * Get several stash tabs (or substash tabs), as many at once as GGG's
     * stash-request-limit window allows. See ApiClient::getMany() for the
     * pacing, waits and 429 handling.
     *
     * Each entry is a stash id, or a [stash id, substash id] pair for a
     * child of a folder, map or unique tab. Results keep the caller's keys and
     * order. A tab that fails (404, 429 given up on, connection failure, ...)
     * lands in `failures` under its key with the exception get() would have
     * thrown; the others still come back.
     *
     * @param  array<array-key, string|array{0: string, 1?: string|null}>  $stashes
     * @param  (Closure(array-key, StashTab|VaalApiException): void)|null  $onResult  Called as each tab settles, in landing order
     * @return BatchResult<StashTab>
     *
     * @throws \InvalidArgumentException When an entry is neither a stash id nor a [stash id, substash id] pair
     */
    public function getMany(array $stashes, ?Closure $onResult = null): BatchResult
    {
        $this->client->requireScope(Scope::Stashes, 'StashResource');

        $paths = [];

        foreach ($stashes as $key => $stash) {
            [$stashId, $substashId] = match (true) {
                is_string($stash) => [$stash, null],
                is_array($stash) && is_string($stash[0] ?? null) => [$stash[0], $stash[1] ?? null],
                default => throw new \InvalidArgumentException("Stash entry '{$key}' is neither a stash id nor a [stash id, substash id] pair"),
            };

            $paths[$key] = $this->tabPath($stashId, $substashId);
        }

        $batch = $this->client->getMany($paths, $onResult === null ? null : function (int|string $key, ApiResponse|VaalApiException $result) use ($onResult): void {
            $onResult($key, $result instanceof ApiResponse ? self::tab($result) : $result);
        });

        return new BatchResult(
            array_map(self::tab(...), $batch->results),
            $batch->failures,
        );
    }

    private static function tab(ApiResponse $response): StashTab
    {
        return StashTab::fromArray($response->data()['stash'] ?? []);
    }

    private function tabPath(string $stashId, ?string $substashId): string
    {
        $path = $this->buildPath('/stash')
            .'/'.rawurlencode($this->league)
            .'/'.rawurlencode($stashId);

        if ($substashId !== null) {
            $path .= '/'.rawurlencode($substashId);
        }

        return $path;
    }

    /**
     * Build a path with optional realm segment.
     *
     * @param  string  $base  Base path
     */
    private function buildPath(string $base): string
    {
        if ($this->realm !== null) {
            return $base.'/'.$this->realm->value;
        }

        return $base;
    }
}
