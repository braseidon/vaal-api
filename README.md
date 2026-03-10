# Vaal API

[![Tests](https://github.com/braseidon/vaal-api/actions/workflows/tests.yml/badge.svg)](https://github.com/braseidon/vaal-api/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/braseidon/vaal-api.svg)](https://packagist.org/packages/braseidon/vaal-api)
[![License](https://img.shields.io/packagist/l/braseidon/vaal-api.svg)](https://packagist.org/packages/braseidon/vaal-api)
[![PHP Version](https://img.shields.io/packagist/php-v/braseidon/vaal-api.svg)](https://packagist.org/packages/braseidon/vaal-api)

PHP client for GGG's Path of Exile API. Wraps both the OAuth 2.0 API and the public API with rate limiting, automatic token refresh, and typed DTOs.

Built on [league/oauth2-client](https://github.com/thephpleague/oauth2-client) and Guzzle.

## Requirements

- PHP 8.2+
- A GGG developer application ([register here](https://www.pathofexile.com/developer/apps))

## Installation

```bash
composer require braseidon/vaal-api
```

Laravel auto-discovers the service provider. To publish the config:

```bash
php artisan vendor:publish --tag=vaal-api-config
```

## Configuration

Add these to your `.env`:

```env
POE_CLIENT_ID=your-client-id
POE_CLIENT_SECRET=your-client-secret
POE_REDIRECT_URI=https://yoursite.com/auth/poe/callback
POE_API_CONTACT=you@example.com
```

### Rate limiting options

```env
# What to do when a rate limit is about to be exceeded
# Options: sleep (default), exception, callback, log
POE_RATE_LIMIT_STRATEGY=sleep

# Margin to avoid riding the limit. 0.2 = treat a 10-request limit as 8.
POE_RATE_LIMIT_SAFETY_MARGIN=0.2

# Automatically retry on 429/503 responses
POE_RATE_LIMIT_AUTO_RETRY=true
POE_RATE_LIMIT_MAX_RETRIES=3
```

Rate limiting works in two layers:

1. **Pre-flight checks** track state from previous responses and predict whether the next request will exceed a limit. The configured strategy controls what happens: `sleep` waits it out, `exception` throws `RateLimitException`, `callback` calls your closure, and `log` logs a warning and continues anyway.

2. **Retry middleware** catches 429/503 responses that slip through pre-flight checks (e.g. on cold start when no state exists). Reads the `Retry-After` header and retries automatically.

### Rate limit strategy: callback

The `callback` strategy lets you handle rate limits yourself. Pass a closure in the config array:

```php
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\RateLimit\RateLimitResult;

$client = new ApiClient([
    ...config('vaal-api'),
    'rate_limit' => [
        'strategy' => 'callback',
        'callback' => function (RateLimitResult $result) {
            Log::warning("Rate limit approaching: {$result->reason}", [
                'policy'  => $result->policy,
                'wait'    => $result->waitSeconds,
            ]);

            // You decide what to do: sleep, queue the job for later, etc.
            if ($result->waitSeconds < 5) {
                sleep($result->waitSeconds);
            } else {
                throw new \RuntimeException("Rate limit too long: {$result->waitSeconds}s");
            }
        },
    ],
]);
```

The `RateLimitResult` tells you everything you need: whether the request can proceed (`$result->canProceed`), how long to wait (`$result->waitSeconds`), which policy triggered it (`$result->policy`), and a human-readable reason (`$result->reason`).

## Usage

### OAuth login flow

GGG uses OAuth 2.0 with PKCE (S256). The provider handles PKCE automatically.

```php
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Auth\Token;

$client   = app(ApiClient::class);
$provider = $client->getAuthProvider();

// 1. Generate the authorization URL
//    You can pass scope strings directly, or use the Scope enum:
use Braseidon\VaalApi\Enums\Scope;

$authUrl = $provider->getAuthorizationUrl([
    'scope' => implode(' ', Scope::allAccount()), // all account scopes
    // or pick specific ones:
    // 'scope' => implode(' ', [Scope::Characters->value, Scope::Stashes->value]),
]);

// Store the PKCE verifier and state in the session
session(['oauth2_pkce_code' => $provider->getPkceCode()]);
session(['oauth2_state' => $provider->getState()]);

return redirect($authUrl);
```

In your callback handler:

```php
// 2. Exchange the authorization code for a token
$provider->setPkceCode(session('oauth2_pkce_code'));

$accessToken = $provider->getAccessToken('authorization_code', [
    'code' => $request->get('code'),
]);

// 3. Wrap it in the Vaal Token DTO
$token = Token::fromAccessToken($accessToken);

// $token->username  => "PlayerName#1234"
// $token->sub       => account UUID (stable across name changes)

// 4. Persist it however you want
$user->update($token->toArray());
```

### Token helpers

The `Token` class has a few methods for checking state before you make requests:

```php
$token->isExpired();              // has the access token expired?
$token->needsRefresh();           // will it expire within 5 minutes? (buffer is configurable)
$token->needsRefresh(600);        // will it expire within 10 minutes?
$token->hasScope(Scope::Stashes); // did the user grant this scope?
$token->hasScope('account:characters'); // string works too
```

The client handles token refresh automatically before each request, so you don't need to check `needsRefresh()` yourself for normal API calls. These are more useful for application logic - hiding UI elements when a scope wasn't granted, or skipping a queued job if the token is expired and has no refresh token.

### Fetching characters

```php
use Braseidon\VaalApi\VaalApi;
use Braseidon\VaalApi\Auth\Token;

// Hydrate a token from your database
$token = Token::fromArray($user->only([
    'access_token', 'refresh_token', 'expires_at', 'scope', 'username', 'sub',
]));

$api = VaalApi::for($token, config('vaal-api'));

// Register a callback so you don't lose the new token after a refresh.
// GGG refresh tokens are single-use: once refreshed, the old one is dead.
$api->onTokenRefresh(function (Token $newToken) use ($user) {
    $user->update($newToken->toArray());
});

// List all characters (rate limit: 2 req/10s - tightest limit in the API)
$characters = $api->characters()->list();

foreach ($characters as $summary) {
    echo $summary->name() . ' - Level ' . $summary->level() . ' ' . $summary->class() . "\n";
    // Note: class() returns the ascendancy name, not the base class.
    // "Necromancer", not "Witch". See gotchas below.
}

// Get full character data (equipment, passives, jewels - 200-320KB response)
$character = $api->characters()->get('MyCharacterName');

$character->level();
$character->equipment();
$character->passiveHashes();      // allocated node IDs
$character->masteryEffects();     // node hash => effect hash
$character->banditChoice();       // "kraityn", "alira", "oak", or "eramir"
$character->alternateAscendancy(); // bloodline ascendancy if selected
```

### Fetching stash tabs

Stash endpoints are PoE1 only and require a league name.

```php
// List all stash tabs in Mirage league
$stashes = $api->stashes('Mirage')->list();

foreach ($stashes as $tab) {
    echo $tab->name() . ' (' . $tab->type() . ")\n";
    // $tab->color() returns "ff0000", not "#ff0000" - no hash prefix
}

// Get a single stash tab with all its items (~207KB)
$stash = $api->stashes('Mirage')->get($tab->id());

foreach ($stash->items() as $item) {
    // full item data
}

// Nested tabs (e.g. quad stash sub-tabs)
$stash = $api->stashes('Mirage')->get($tabId, $substashId);
```

### Caching responses in Laravel

The package doesn't include caching, so you wire it up however fits your app. Character list is the most important one to cache since it has the tightest rate limit.

```php
use Illuminate\Support\Facades\Cache;

$characters = Cache::remember(
    "poe:characters:{$user->id}",
    now()->addMinutes(5),
    fn () => $api->characters()->list(),
);

// For stash tabs, longer TTL is usually fine
$stashList = Cache::remember(
    "poe:stashes:{$user->id}:Mirage",
    now()->addMinutes(15),
    fn () => $api->stashes('Mirage')->list(),
);
```

### Public API (no auth)

```php
use Braseidon\VaalApi\VaalApi;

$public = VaalApi::public(config('vaal-api'));

$leagues = $public->leagues()->list();
$tradeResults = $public->trade()->search('Mirage', $queryPayload);
$items = $public->trade()->fetch($tradeResults->id(), $tradeResults->itemIds());
```

### Realm support

Most endpoints accept an optional realm. Defaults to PC when omitted.

```php
use Braseidon\VaalApi\Enums\Realm;

$api->characters(Realm::Xbox)->list();
$api->stashes('Mirage', Realm::Sony)->list();
```

### Error handling

```php
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;

try {
    $character = $api->characters()->get('SomeName');
} catch (RateLimitException $e) {
    $e->getRetryAfter();        // seconds to wait
    $e->getRateLimitResult();   // full RateLimitResult DTO
} catch (AuthenticationException $e) {
    // Token expired/invalid, or missing required scope
} catch (ResourceNotFoundException $e) {
    // Character doesn't exist or is private
} catch (ServerException $e) {
    // GGG's servers are having a bad day
}
```

## Available endpoints

### OAuth (authenticated)

| Resource | Method | Description | Scope | Game |
|----------|--------|-------------|-------|------|
| `profile()` | `get()` | Account profile | `account:profile` | Both |
| `characters()` | `list()` | All account characters | `account:characters` | Both |
| `characters()` | `get($name)` | Full character detail | `account:characters` | Both |
| `itemFilters()` | `list()`, `get()`, `create()`, `update()` | Item filters | `account:item_filter` | Both |
| `leagues()` | `list()`, `get()` | League data | `service:leagues` | Both |
| `leagues()` | `ladder()`, `eventLadder()` | League ladders | `service:leagues:ladder` | PoE1 |
| `currencyExchange()` | Exchange market history | Currency rates | `service:cxapi` | Both |
| `stashes($league)` | `list()` | All stash tabs in a league | `account:stashes` | PoE1 |
| `stashes($league)` | `get($id, $substashId?)` | Single stash with items | `account:stashes` | PoE1 |
| `accountLeagues()` | `list()` | Account's leagues | `account:leagues` | PoE1 |
| `leagueAccount($league)` | `get()` | Atlas passives | `account:league_accounts` | PoE1 |
| `guild()` | Guild stash endpoints | Guild data | `account:guild:stashes` | PoE1 |
| `publicStashTabs()` | Public stash stream | River-style stream | `service:psapi` | PoE1 |
| `pvpMatches()` | PvP match data | PvP | `service:pvp_matches` | PoE1 |

### Public (no auth)

| Resource | Method | Description | Game |
|----------|--------|-------------|------|
| `public()->leagues()` | `list()` | Public league list | Both |
| `public()->characters($account)` | `list()` | Account's public characters | Both |
| `public()->stashTabs()` | `list()` | Public stash tab stream | PoE1 |
| `public()->trade()` | `search()`, `fetch()`, `items()`, `stats()`, `static()` | Trade API | Both |

GGG's PoE2 API coverage is still limited. Endpoints marked "Both" accept `Realm::Poe2`, but the response structures have some PoE2-specific fields (and are missing some PoE1-specific ones like `masteryEffects` and `banditChoice`). See GGG's [API reference](https://www.pathofexile.com/developer/docs/api-resource-description) for the full field breakdown.

> Only endpoints the author has access to have been tested. The others follow the same patterns and match GGG's docs, but haven't been verified against live responses. If something is off, open an issue.

## GGG API gotchas

Things that will bite you if you don't know about them.

- **Character `class` is the ascendancy name**, not the base class. `"Necromancer"` not `"Witch"`. You need a lookup table to get the base class from the ascendancy.

- **`current` field is absence-based.** Only present as `true` on the last-played character. The key is missing on all other characters, not set to `false`.

- **Character list has the tightest rate limit.** 2 requests per 10 seconds. Cache this endpoint. The character detail endpoint is more generous at 5 req/10s.

- **Authorization codes expire in 30 seconds.** Exchange them for a token immediately in your callback. If you have any slow middleware or redirects between receiving the code and exchanging it, you'll get failures.

- **Refresh tokens are single-use.** After refreshing, the old refresh token is immediately invalid. If you don't persist the new token, you've lost access. Use `onTokenRefresh()` to handle this.

- **Stash tab color has no `#` prefix.** `"ff0000"` not `"#ff0000"`. Prepend it yourself if you need it for CSS.

- **`metadata.public` is absence-based.** The key only exists when `true`. Check with `isset()` or `?? false`, not strict equality.

## License

MIT
