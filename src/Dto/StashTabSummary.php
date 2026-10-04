<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Stash tab metadata from the list endpoint (GGG type StashTab, without items).
 *
 * GGG nests the tab's display flags under `metadata`: `public` and `folder`
 * are present only as true, `colour` is a hex string without the # prefix
 * (e.g. "ff0000"). The top-level `folder` and `parent` are tab ids.
 */
readonly class StashTabSummary
{
    /**
     * @param  string  $id  Stash tab ID (10-char hex)
     * @param  string  $name  Tab display name
     * @param  string  $type  Tab type (NormalStash, PremiumStash, QuadStash, etc.)
     * @param  int  $index  Tab position index (GGG omits it on child tabs)
     * @param  string|null  $color  Hex colour from `metadata.colour`, without # prefix
     * @param  string|null  $folder  Id of the folder this tab sits in
     * @param  StashTabSummary[]  $children  Child tabs (container tabs read through the detail endpoint)
     * @param  array|null  $metadata  Raw metadata (`public`, `folder`, `colour`, `items`, ...)
     * @param  string|null  $parent  Id of the parent tab, on a child tab
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public int $index,
        public ?string $color = null,
        public ?string $folder = null,
        public array $children = [],
        public ?array $metadata = null,
        public ?string $parent = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param  array  $data  Single stash tab entry
     */
    public static function fromArray(array $data): self
    {
        $children = [];

        foreach ($data['children'] ?? [] as $child) {
            $children[] = self::fromArray($child);
        }

        $metadata = $data['metadata'] ?? null;
        $colour = $metadata['colour'] ?? null;
        $folder = $data['folder'] ?? null;
        $parent = $data['parent'] ?? null;

        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            type: $data['type'] ?? 'Unknown',
            index: $data['index'] ?? 0,
            color: is_string($colour) ? $colour : null,
            folder: is_string($folder) ? $folder : null,
            children: $children,
            metadata: $metadata,
            parent: is_string($parent) ? $parent : null,
        );
    }

    /**
     * Whether this tab is set to public.
     */
    public function isPublic(): bool
    {
        return ($this->metadata['public'] ?? false) === true;
    }

    /**
     * Whether this tab is itself a folder (`metadata.folder`).
     */
    public function isFolder(): bool
    {
        return ($this->metadata['folder'] ?? false) === true;
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'index' => $this->index,
            'color' => $this->color,
            'folder' => $this->folder,
            'parent' => $this->parent,
            'children' => array_map(fn (self $c) => $c->toArray(), $this->children),
            'metadata' => $this->metadata,
        ];
    }
}
