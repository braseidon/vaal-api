# Vaal API

PHP client for the [Path of Exile API](https://www.pathofexile.com/developer/docs) with OAuth 2.0 (PKCE), automatic rate limiting, and full endpoint coverage.

Built on [league/oauth2-client](https://github.com/thephpleague/oauth2-client) and [Guzzle](https://github.com/guzzle/guzzle). Works standalone or with Laravel.

> This product isn't affiliated with or endorsed by Grinding Gear Games in any way.

## Requirements

- PHP 8.2+
- A [GGG developer application](https://www.pathofexile.com/developer/apps)

## Installation

```bash
composer require braseidon/vaal-api
```

## Quick Start

```php
use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\VaalApi;

$token = Token::fromArray([
    'access_token' => 'your-access-token',
    'refresh_token' => 'your-refresh-token',
    'expires_at' => time() + 86400,
    'scope' => 'account:profile account:characters',
]);

$api = VaalApi::for($token, [
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'user_agent' => [
        'version' => '1.0.0',
        'contact' => 'your@email.com',
    ],
]);

// Get account profile
$profile = $api->profile()->get();
echo $profile->name; // "PlayerName#1234"
echo $profile->uuid; // Stable account identifier

// List characters (cache this - tightest rate limit)
$characters = $api->characters()->list();
foreach ($characters as $char) {
    echo "{$char->name} - Level {$char->level} {$char->class}\n";
}

// Get full character data (200-300KB response)
$character = $api->characters()->get('MyCharacterName');
$character->equipment();     // Array of item data
$character->passiveHashes(); // Allocated tree nodes
$character->banditChoice();  // "Eramir", "Alira", etc.
$character->raw();           // Full decoded response

// List stash tabs
$tabs = $api->stashes('Standard')->list();
$tabDetail = $api->stashes('Standard')->get($tabs[0]->id);
$items = $tabDetail->items();
```

## OAuth Flow

Vaal API uses `league/oauth2-client` with GGG's required PKCE (S256) flow.

```php
use Braseidon\VaalApi\Auth\PathOfExileProvider;
use Braseidon\VaalApi\Auth\Token;

$provider = new PathOfExileProvider([
    'clientId' => 'your-client-id',
    'clientSecret' => 'your-client-secret',
    'redirectUri' => 'https://yourapp.com/callback',
]);

// Step 1: Redirect to GGG (PKCE is automatic)
$authUrl = $provider->getAuthorizationUrl([
    'scope' => 'account:profile account:characters account:stashes account:leagues',
]);
$state = $provider->getState();
$pkceCode = $provider->getPkceCode();
// Store $state and $pkceCode in session, then redirect to $authUrl

// Step 2: Handle callback (must happen within 30 seconds)
$provider->setPkceCode($pkceCode); // Restore from session
$accessToken = $provider->getAccessToken('authorization_code', [
    'code' => $_GET['code'],
]);

$token = Token::fromAccessToken($accessToken);
echo $token->username; // "PlayerName#1234"
echo $token->sub;      // GGG account UUID
// Store $token->toArray() in your database
```

## Token Refresh

Access tokens last 28 days (confidential clients). Refresh tokens last 90 days.

```php
// Automatic refresh with callback
$api = VaalApi::for($token, $config)
    ->onTokenRefresh(function (Token $newToken) {
        // Persist the new token - old refresh token is now invalid
        $this->saveToken($newToken);
    });

// All API calls will auto-refresh when needed
$profile = $api->profile()->get();
```

## Rate Limiting

GGG enforces per-endpoint rate limits with multiple time windows. Vaal API handles this in two layers:

1. **Pre-flight checks** - Before each request, the client checks tracked rate limit state from previous response headers. If a limit is about to be exceeded, the configured strategy is applied (sleep, throw, callback, or log). This prevents most 429s.
2. **Retry middleware** - If a 429 (or 503) response slips through, Guzzle middleware automatically sleeps for the `Retry-After` duration and retries the request. After max retries, a `RateLimitException` is thrown.

### Auto-Retry

Enabled by default. The middleware reads the `Retry-After` header from 429/503 responses and retries automatically:

```php
$api = VaalApi::for($token, [
    'rate_limit' => [
        'auto_retry'  => true, // default
        'max_retries' => 3,    // default
    ],
]);
```

Set `auto_retry` to `false` for fail-fast behavior (e.g. queue the job for later instead of blocking):

```php
'rate_limit' => ['auto_retry' => false]
```

### Safety Margin

Treats rate limits as lower than reported to avoid hitting the actual limit:

```php
$api = VaalApi::for($token, [
    'rate_limit' => [
        'safety_margin' => 0.2, // 20% buffer (default)
    ],
]);
// A 10-request limit becomes 8 effective requests
```

### Pre-flight Strategies

What happens when the pre-flight check predicts a rate limit will be exceeded:

```php
// Sleep and continue (default)
'rate_limit' => ['strategy' => 'sleep']

// Throw RateLimitException
'rate_limit' => ['strategy' => 'exception']

// Call your handler
'rate_limit' => [
    'strategy' => 'callback',
    'callback' => function (RateLimitResult $result) {
        Log::warning("Rate limited: {$result->reason}");
        dispatch(new RetryApiCallJob($result));
    },
]

// Log warning and continue (development)
'rate_limit' => [
    'strategy' => 'log',
    // Requires a PSR-3 logger in config
]
```

### Known Rate Limits

| Endpoint | Policy | Limits |
|----------|--------|--------|
| `GET /character` (list) | `character-list-request-limit` | 2/10s, 5/5min |
| `GET /character/{name}` | `character-request-limit` | 5/10s, 30/5min |
| `GET /account/leagues` | `league-request-limit` | 5/10s, 10/60s |
| `GET /stash/{league}` (list) | `stash-list-request-limit` | 10/15s, 30/60s |
| `GET /stash/{league}/{id}` | `stash-request-limit` | 15/10s, 30/5min |

## Public API (No Auth)

```php
$public = VaalApi::public($config);

// Leagues
$leagues = $public->leagues()->list();

// Characters (public profiles only)
$chars = $public->characters('AccountName')->list();
$passives = $public->characters('AccountName')->passives('CharName');
$items = $public->characters('AccountName')->items('CharName');

// Trade
$results = $public->trade()->search('Standard', $query);
$listings = $public->trade()->fetch($results->id, array_slice($results->result, 0, 10));
$allItems = $public->trade()->items();  // CDN-cached reference data
$allStats = $public->trade()->stats();
```

## All Endpoints

### OAuth API (`api.pathofexile.com`)

| Method | Resource | Scope |
|--------|----------|-------|
| `$api->profile()->get()` | Account profile | `account:profile` |
| `$api->characters()->list()` | Character list | `account:characters` |
| `$api->characters()->get($name)` | Character detail | `account:characters` |
| `$api->stashes($league)->list()` | Stash tab list | `account:stashes` |
| `$api->stashes($league)->get($id)` | Stash tab detail | `account:stashes` |
| `$api->leagues()->list()` | League list | `service:leagues` |
| `$api->leagues()->get($id)` | League detail | `service:leagues` |
| `$api->leagues()->ladder($id)` | League ladder | `service:leagues:ladder` |
| `$api->leagues()->eventLadder($id)` | Event ladder | `service:leagues:ladder` |
| `$api->accountLeagues()->list()` | Account leagues | `account:leagues` |
| `$api->leagueAccount($league)->get()` | Atlas passives | `account:league_accounts` |
| `$api->itemFilters()->list()` | Item filter list | `account:item_filter` |
| `$api->itemFilters()->get($id)` | Item filter detail | `account:item_filter` |
| `$api->itemFilters()->create($data)` | Create filter | `account:item_filter` |
| `$api->itemFilters()->update($id, $data)` | Update filter | `account:item_filter` |
| `$api->pvpMatches()->list()` | PvP match list | `service:pvp_matches` |
| `$api->pvpMatches()->get($id)` | PvP match detail | `service:pvp_matches` |
| `$api->pvpMatches()->ladder($id)` | PvP ladder | `service:pvp_matches:ladder` |
| `$api->guild()->stashes($league)->list()` | Guild stash list | `account:guild:stashes` |
| `$api->guild()->stashes($league)->get($id)` | Guild stash detail | `account:guild:stashes` |
| `$api->publicStashTabs()->get()` | Public stash river | `service:psapi` |
| `$api->currencyExchange()->get()` | Currency rates | `service:cxapi` |

### Public API (`www.pathofexile.com`)

| Method | Endpoint |
|--------|----------|
| `$api->public()->leagues()->list()` | `/api/leagues` |
| `$api->public()->characters($acct)->list()` | `/character-window/get-characters` |
| `$api->public()->characters($acct)->passives($char)` | `/character-window/get-passive-skills` |
| `$api->public()->characters($acct)->items($char)` | `/character-window/get-items` |
| `$api->public()->stashTabs()->get()` | `/api/public-stash-tabs` |
| `$api->public()->trade()->search($league, $query)` | `/api/trade/search/{league}` |
| `$api->public()->trade()->fetch($id, $hashes)` | `/api/trade/fetch/{hashes}` |
| `$api->public()->trade()->items()` | `/api/trade/data/items` |
| `$api->public()->trade()->stats()` | `/api/trade/data/stats` |
| `$api->public()->trade()->static()` | `/api/trade/data/static` |

### Realms

Most endpoints accept an optional realm parameter:

```php
use Braseidon\VaalApi\Enums\Realm;

$api->characters(Realm::Xbox)->list();
$api->stashes('Standard', Realm::Sony)->list();
```

## Configuration

```php
$config = [
    'client_id' => 'your-client-id',
    'client_secret' => 'your-client-secret',
    'redirect_uri' => 'https://yourapp.com/callback',
    'scopes' => ['account:profile', 'account:characters'],

    'user_agent' => [
        'version' => '1.0.0',       // Your app version
        'contact' => 'you@email.com', // Required by GGG
    ],

    'rate_limit' => [
        'strategy' => 'sleep',       // sleep|exception|callback|log (pre-flight)
        'safety_margin' => 0.2,      // 0.0 to 1.0
        'auto_retry' => true,        // Retry 429/503 via Guzzle middleware
        'max_retries' => 3,          // Max retry attempts
    ],

    'timeout' => 30,                 // Request timeout in seconds
    'default_realm' => null,         // null = PC
    'base_url' => 'https://api.pathofexile.com',
    'public_url' => 'https://www.pathofexile.com',
];
```

## Laravel

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=vaal-api-config
```

Set your `.env`:

```env
POE_CLIENT_ID=your-client-id
POE_CLIENT_SECRET=your-client-secret
POE_REDIRECT_URI=https://yourapp.com/auth/poe/callback
POE_API_CONTACT=you@email.com
POE_API_VERSION=1.0.0
POE_RATE_LIMIT_STRATEGY=sleep
POE_RATE_LIMIT_SAFETY_MARGIN=0.2
POE_RATE_LIMIT_AUTO_RETRY=true
POE_RATE_LIMIT_MAX_RETRIES=3
```

Use the facade:

```php
use Braseidon\VaalApi\Laravel\Facades\VaalApi;

$profile = VaalApi::withToken($token)->profile()->get();
```

### Token Storage

For Laravel apps, use the included `EloquentTokenStore`:

```php
use Braseidon\VaalApi\Laravel\EloquentTokenStore;

$store = new EloquentTokenStore(
    modelClass: OauthAccount::class,
    identifierColumn: 'provider_user_id',
    dataColumn: 'provider_data',
);

$token = $store->getToken($uuid);
$store->saveToken($uuid, $newToken);
```

For non-Laravel apps, use `FileTokenStore`:

```php
use Braseidon\VaalApi\Auth\FileTokenStore;

$store = new FileTokenStore('/path/to/tokens');
$store->saveToken('user-uuid', $token);
$token = $store->getToken('user-uuid');
```

## GGG API Gotchas

- **Character `class` is the ascendancy name**, not the base class. `"Necromancer"` not `"Witch"`.
- **`current` field is absence-based.** Only present as `true` on the last-played character. Not `false` on others - the key is simply missing.
- **Character list has the tightest rate limit.** 2 requests per 10 seconds. Cache aggressively.
- **Authorization codes expire in 30 seconds.** Exchange them immediately.
- **Refresh tokens are single-use.** After refresh, the old token is immediately invalid.
- **Stash tab color has no `#` prefix.** `"ff0000"` not `"#ff0000"`.
- **`metadata.public` is absence-based.** The key only exists when `true`.

## License

MIT
