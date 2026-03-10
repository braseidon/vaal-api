<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Full stash tab detail with items.
 *
 * Thin wrapper around the raw response. Stash tabs can contain
 * hundreds of items with complex item structures.
 */
readonly class StashTab
{
    /**
     * @param array $data Raw stash tab response data
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data Decoded JSON from /stash/{league}/{id}
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The complete raw API response.
     *
     * @return array
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * Stash tab ID.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->data['id'] ?? '';
    }

    /**
     * Tab display name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->data['n'] ?? $this->data['name'] ?? '';
    }

    /**
     * Tab type (NormalStash, PremiumStash, etc.).
     *
     * @return string
     */
    public function type(): string
    {
        return $this->data['type'] ?? '';
    }

    /**
     * Items in this stash tab.
     *
     * @return array
     */
    public function items(): array
    {
        return $this->data['items'] ?? [];
    }

    /**
     * Tab metadata.
     *
     * @return array
     */
    public function metadata(): array
    {
        return $this->data['metadata'] ?? [];
    }
}
