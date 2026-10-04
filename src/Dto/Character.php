<?php

namespace Braseidon\VaalApi\Dto;

/**
 * Full character detail from the GET /character/{name} endpoint.
 *
 * This is a thin wrapper around the raw API response. Character detail
 * responses are 200-300KB with deeply nested structures that change
 * between leagues, so fully typing every field is impractical.
 *
 * GGG wraps the character: {"character": {...}}. raw() returns the whole
 * response as received, wrapper included; the convenience accessors read
 * inside the `character` key.
 */
readonly class Character
{
    /**
     * @param  array  $data  Raw character response, {"character": {...}}
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param  array  $data  Decoded JSON from /character/{name}, wrapper included
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The complete raw API response, {"character": {...}}.
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * The character object inside the response wrapper.
     */
    private function character(): array
    {
        $character = $this->data['character'] ?? null;

        return is_array($character) ? $character : [];
    }

    /**
     * Character UUID.
     */
    public function id(): string
    {
        return $this->character()['id'] ?? '';
    }

    /**
     * Character name.
     */
    public function name(): string
    {
        return $this->character()['name'] ?? '';
    }

    /**
     * The ascendancy name (GGG quirk: returns ascendancy, not base class).
     */
    public function class(): string
    {
        return $this->character()['class'] ?? '';
    }

    /**
     * League name, if in a league.
     */
    public function league(): ?string
    {
        return $this->character()['league'] ?? null;
    }

    /**
     * Character level.
     */
    public function level(): int
    {
        return $this->character()['level'] ?? 0;
    }

    /**
     * Total experience.
     */
    public function experience(): int
    {
        return $this->character()['experience'] ?? 0;
    }

    /**
     * Equipped items.
     */
    public function equipment(): array
    {
        return $this->character()['equipment'] ?? [];
    }

    /**
     * Inventory items.
     */
    public function inventory(): array
    {
        return $this->character()['inventory'] ?? [];
    }

    /**
     * Rucksack items.
     */
    public function rucksack(): array
    {
        return $this->character()['rucksack'] ?? [];
    }

    /**
     * Socketed jewels.
     */
    public function jewels(): array
    {
        return $this->character()['jewels'] ?? [];
    }

    /**
     * Passive skill data (hashes, masteries, choices).
     */
    public function passives(): array
    {
        return $this->character()['passives'] ?? [];
    }

    /**
     * Allocated passive tree node IDs.
     *
     * @return int[]
     */
    public function passiveHashes(): array
    {
        return $this->passives()['hashes'] ?? [];
    }

    /**
     * Cluster jewel node IDs (separate ID space from the main tree).
     *
     * @return int[]
     */
    public function passiveHashesEx(): array
    {
        return $this->passives()['hashes_ex'] ?? [];
    }

    /**
     * Mastery effect selections: node hash => effect hash.
     *
     * @return array<int, int>
     */
    public function masteryEffects(): array
    {
        return $this->passives()['mastery_effects'] ?? [];
    }

    /**
     * Bandit choice (e.g. "Kraityn", "Alira", "Oak", or "Eramir").
     */
    public function banditChoice(): ?string
    {
        return $this->passives()['bandit_choice'] ?? null;
    }

    /**
     * Major pantheon god selection.
     */
    public function pantheonMajor(): ?string
    {
        return $this->passives()['pantheon_major'] ?? null;
    }

    /**
     * Minor pantheon god selection.
     */
    public function pantheonMinor(): ?string
    {
        return $this->passives()['pantheon_minor'] ?? null;
    }

    /**
     * Bloodline ascendancy name, if using one.
     */
    public function alternateAscendancy(): ?string
    {
        return $this->passives()['alternate_ascendancy'] ?? null;
    }
}
