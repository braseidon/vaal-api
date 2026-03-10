<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Stash tab metadata from the list endpoint.
 *
 * Note: The `public` field uses absence-based logic. It's only present
 * as true when the tab is public. Private tabs omit the field entirely.
 *
 * Color is a hex string without the # prefix (e.g. "ff0000").
 */
readonly class StashTabSummary
{
    /**
     * @param string              $id       Stash tab ID (10-char hex)
     * @param string              $name     Tab display name
     * @param string              $type     Tab type (NormalStash, PremiumStash, QuadStash, etc.)
     * @param int                 $index    Tab position index
     * @param string|null         $color    Hex color without # prefix
     * @param bool|null           $folder   Whether this is a folder tab
     * @param StashTabSummary[]   $children Child tabs (for folder type)
     * @param array|null          $metadata Extra metadata (includes `public` flag)
     */
    public function __construct(
        public string  $id,
        public string  $name,
        public string  $type,
        public int     $index,
        public ?string $color    = null,
        public ?bool   $folder   = null,
        public array   $children = [],
        public ?array  $metadata = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * Handles GGG's abbreviated field names (n, i, colour).
     *
     * @param array $data Single stash tab entry
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $children = [];

        foreach ($data['children'] ?? [] as $child) {
            $children[] = self::fromArray($child);
        }

        return new self(
            id:       $data['id'],
            name:     $data['n'] ?? $data['name'] ?? '',
            type:     $data['type'],
            index:    $data['i'] ?? $data['index'] ?? 0,
            color:    $data['colour'] ?? $data['color'] ?? null,
            folder:   $data['folder'] ?? null,
            children: $children,
            metadata: $data['metadata'] ?? null,
        );
    }

    /**
     * Whether this tab is set to public.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return ($this->metadata['public'] ?? false) === true;
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'type'     => $this->type,
            'index'    => $this->index,
            'color'    => $this->color,
            'folder'   => $this->folder,
            'children' => array_map(fn (self $c) => $c->toArray(), $this->children),
            'metadata' => $this->metadata,
        ];
    }
}
