<?php

namespace Braseidon\VaalApi\Dto;

/**
 * League data from the API.
 */
readonly class League
{
    /**
     * @param string      $id          League identifier (e.g. "Standard", "Mirage")
     * @param string|null $realm       Game realm
     * @param string|null $url         League URL on pathofexile.com
     * @param string|null $startAt     ISO 8601 start timestamp
     * @param string|null $endAt       ISO 8601 end timestamp
     * @param string|null $description League description text
     * @param array|null  $category    Category data (includes `current` flag)
     * @param array       $rules       League rules (e.g. hardcore, SSF)
     */
    public function __construct(
        public string  $id,
        public ?string $realm       = null,
        public ?string $url         = null,
        public ?string $startAt     = null,
        public ?string $endAt       = null,
        public ?string $description = null,
        public ?array  $category    = null,
        public array   $rules       = [],
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data League data from API
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          $data['id'],
            realm:       $data['realm'] ?? null,
            url:         $data['url'] ?? null,
            startAt:     $data['startAt'] ?? null,
            endAt:       $data['endAt'] ?? null,
            description: $data['description'] ?? null,
            category:    $data['category'] ?? null,
            rules:       $data['rules'] ?? [],
        );
    }

    /**
     * Whether this is the current active league.
     *
     * GGG indicates current leagues via category.current = true.
     *
     * @return bool
     */
    public function isCurrent(): bool
    {
        return ($this->category['current'] ?? false) === true;
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
            'realm'       => $this->realm,
            'url'         => $this->url,
            'startAt'     => $this->startAt,
            'endAt'       => $this->endAt,
            'description' => $this->description,
            'category'    => $this->category,
            'rules'       => $this->rules,
        ];
    }
}
