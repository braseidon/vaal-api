<?php

namespace Braseidon\VaalApi\Enums;

/**
 * OAuth scopes available for GGG API applications.
 *
 * Account scopes require user authorization. Service scopes are
 * for confidential clients only.
 *
 * @see https://www.pathofexile.com/developer/docs
 */
enum Scope: string
{
    case Profile             = 'account:profile';
    case Characters          = 'account:characters';
    case Stashes             = 'account:stashes';
    case Leagues             = 'account:leagues';
    case LeagueAccounts      = 'account:league_accounts';
    case ItemFilter          = 'account:item_filter';
    case GuildStashes        = 'account:guild:stashes';

    case ServiceLeagues      = 'service:leagues';
    case ServiceLeaguesLadder    = 'service:leagues:ladder';
    case ServicePsapi        = 'service:psapi';
    case ServiceCxapi        = 'service:cxapi';
    case ServicePvpMatches       = 'service:pvp_matches';
    case ServicePvpMatchesLadder = 'service:pvp_matches:ladder';

    /**
     * All account-level scopes (require user authorization).
     *
     * @return string[]
     */
    public static function allAccount(): array
    {
        return [
            self::Profile->value,
            self::Characters->value,
            self::Stashes->value,
            self::Leagues->value,
            self::LeagueAccounts->value,
            self::ItemFilter->value,
            self::GuildStashes->value,
        ];
    }

    /**
     * All service-level scopes (confidential clients only).
     *
     * @return string[]
     */
    public static function allService(): array
    {
        return [
            self::ServiceLeagues->value,
            self::ServiceLeaguesLadder->value,
            self::ServicePsapi->value,
            self::ServiceCxapi->value,
            self::ServicePvpMatches->value,
            self::ServicePvpMatchesLadder->value,
        ];
    }

    /**
     * All available scopes.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array_merge(self::allAccount(), self::allService());
    }
}
