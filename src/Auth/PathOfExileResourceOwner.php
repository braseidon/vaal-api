<?php

namespace Braseidon\VaalApi\Auth;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

/**
 * Represents the authenticated Path of Exile account.
 *
 * GGG's /profile endpoint returns the account UUID, display name,
 * locale, and optional Twitch/guild connections.
 */
class PathOfExileResourceOwner implements ResourceOwnerInterface
{
    /**
     * @param  array  $data  Profile response data from GGG
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * Account UUID (stable identifier).
     */
    public function getId(): string
    {
        return $this->data['uuid'] ?? '';
    }

    /**
     * Display name with discriminator (e.g. "PlayerName#1234").
     */
    public function getName(): string
    {
        return $this->data['name'] ?? '';
    }

    /**
     * Account realm (pc, xbox, sony).
     */
    public function getRealm(): ?string
    {
        return $this->data['realm'] ?? null;
    }

    /**
     * Account locale preference.
     */
    public function getLocale(): ?string
    {
        return $this->data['locale'] ?? null;
    }

    /**
     * Raw profile data as an array.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
