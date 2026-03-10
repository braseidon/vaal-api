<?php

namespace Braseidon\VaalApi\Laravel\Facades;

use Braseidon\VaalApi\Client\ApiClient;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Vaal API client.
 *
 * @method static ApiClient withToken(\Braseidon\VaalApi\Auth\Token $token)
 * @method static ApiClient onTokenRefresh(\Closure $callback)
 * @method static \Braseidon\VaalApi\Resources\ProfileResource profile()
 * @method static \Braseidon\VaalApi\Resources\CharacterResource characters(?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\StashResource stashes(string $league, ?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\LeagueResource leagues()
 * @method static \Braseidon\VaalApi\Resources\AccountLeagueResource accountLeagues(?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\ItemFilterResource itemFilters()
 * @method static \Braseidon\VaalApi\Resources\LeagueAccountResource leagueAccount(string $league, ?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\PvpMatchResource pvpMatches()
 * @method static \Braseidon\VaalApi\Resources\GuildResource guild(?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\PublicStashTabResource publicStashTabs(?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\CurrencyExchangeResource currencyExchange(?\Braseidon\VaalApi\Enums\Realm $realm = null)
 * @method static \Braseidon\VaalApi\Resources\Public\PublicApiClient public()
 *
 * @see \Braseidon\VaalApi\Client\ApiClient
 */
class VaalApi extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return ApiClient::class;
    }
}
