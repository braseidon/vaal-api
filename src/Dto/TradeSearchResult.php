<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Result from a trade API search.
 *
 * Contains the search ID needed to fetch item details, the list of
 * result hashes, and the total count.
 *
 * @untested Endpoint not yet tested against the live API.
 */
readonly class TradeSearchResult
{
    /**
     * @param string   $id         Search ID for subsequent fetch requests
     * @param string[] $result     Result hashes (max 10 per fetch)
     * @param int      $total      Total number of matching items
     * @param int|null $complexity Query complexity score
     */
    public function __construct(
        public string $id,
        public array  $result,
        public int    $total,
        public ?int   $complexity = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data Trade search response
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:         $data['id'],
            result:     $data['result'] ?? [],
            total:      $data['total'] ?? 0,
            complexity: $data['complexity'] ?? null,
        );
    }
}
