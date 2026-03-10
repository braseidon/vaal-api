<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Character summary from the list endpoint.
 *
 * Note: GGG returns the ascendancy name in the `class` field,
 * not the base class. For example, "Necromancer" instead of "Witch".
 *
 * The `current` field is only present (as true) on the last-played character.
 * It is absent (not false) on all other characters.
 */
readonly class CharacterSummary
{
    /**
     * @param string   $id         Character UUID
     * @param string   $name       Character name
     * @param string   $class      Ascendancy name (not base class)
     * @param string|null $league   League name (null for Standard/Void)
     * @param string   $realm      Game realm
     * @param int      $level      Character level
     * @param int      $experience Total experience
     * @param bool     $current    Whether this is the last-played character
     * @param bool     $ruthless   Whether this is a ruthless character
     * @param int|null $lastActive  Unix timestamp of last activity
     */
    public function __construct(
        public string  $id,
        public string  $name,
        public string  $class,
        public ?string $league,
        public string  $realm,
        public int     $level,
        public int     $experience,
        public bool    $current    = false,
        public bool    $ruthless   = false,
        public ?int    $lastActive = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data Single character entry from the list response
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:         $data['id'],
            name:       $data['name'],
            class:      $data['class'],
            league:     $data['league'] ?? null,
            realm:      $data['realm'] ?? 'pc',
            level:      $data['level'],
            experience: $data['experience'] ?? 0,
            current:    $data['current'] ?? false,
            ruthless:   $data['ruthless'] ?? false,
            lastActive: $data['last_active'] ?? null,
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
            'name'        => $this->name,
            'class'       => $this->class,
            'league'      => $this->league,
            'realm'       => $this->realm,
            'level'       => $this->level,
            'experience'  => $this->experience,
            'current'     => $this->current,
            'ruthless'    => $this->ruthless,
            'last_active' => $this->lastActive,
        ];
    }
}
