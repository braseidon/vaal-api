<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Item filter data from the GGG API.
 *
 * @untested Endpoint not yet tested against the live API.
 */
readonly class ItemFilter
{
    /**
     * @param string      $id          Filter identifier
     * @param string      $name        Filter name
     * @param string      $realm       Game realm
     * @param string|null $description Filter description
     * @param string|null $version     Game version the filter targets
     * @param string|null $type        Filter type
     * @param bool        $public      Whether the filter is publicly visible
     * @param string|null $filter      The filter content (rules text)
     */
    public function __construct(
        public string  $id,
        public string  $name,
        public string  $realm,
        public ?string $description = null,
        public ?string $version     = null,
        public ?string $type        = null,
        public bool    $public      = false,
        public ?string $filter      = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data Item filter data from API
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          $data['id'],
            name:        $data['filter_name'] ?? $data['name'] ?? '',
            realm:       $data['realm'] ?? 'pc',
            description: $data['description'] ?? null,
            version:     $data['version'] ?? null,
            type:        $data['type'] ?? null,
            public:      $data['public'] ?? false,
            filter:      $data['filter'] ?? null,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'filter_name' => $this->name,
            'realm'       => $this->realm,
            'description' => $this->description,
            'version'     => $this->version,
            'type'        => $this->type,
            'public'      => $this->public,
            'filter'      => $this->filter,
        ];
    }
}
