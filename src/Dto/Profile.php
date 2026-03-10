<?php

namespace Braseidon\VaalApi\Dto;

/**
 * GGG account profile.
 *
 * The UUID is the stable account identifier. Display names can change
 * and include a discriminator (e.g. "PlayerName#1234").
 */
readonly class Profile
{
    /**
     * @param string     $uuid   Account UUID (stable identifier)
     * @param string     $name   Display name with discriminator
     * @param string|null $realm Account realm
     * @param string|null $locale Account locale preference
     * @param array|null  $twitch Linked Twitch account data
     * @param array|null  $guild  Guild membership data
     */
    public function __construct(
        public string  $uuid,
        public string  $name,
        public ?string $realm  = null,
        public ?string $locale = null,
        public ?array  $twitch = null,
        public ?array  $guild  = null,
    ) {}

    /**
     * Create from a decoded API response array.
     *
     * @param array $data Decoded JSON from /profile
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uuid:   $data['uuid'],
            name:   $data['name'],
            realm:  $data['realm'] ?? null,
            locale: $data['locale'] ?? null,
            twitch: $data['twitch'] ?? null,
            guild:  $data['guild'] ?? null,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_filter([
            'uuid'   => $this->uuid,
            'name'   => $this->name,
            'realm'  => $this->realm,
            'locale' => $this->locale,
            'twitch' => $this->twitch,
            'guild'  => $this->guild,
        ], fn ($v) => $v !== null);
    }
}
