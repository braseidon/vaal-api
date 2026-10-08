<?php

namespace Braseidon\VaalApi\Client;

use Braseidon\VaalApi\Auth\PathOfExileProvider;
use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Enums\RateLimitStrategy;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Exceptions\ConnectionException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use Braseidon\VaalApi\RateLimit\RateLimitResult;
use Braseidon\VaalApi\RateLimit\RateLimitStore;
use Braseidon\VaalApi\Resources\AccountLeagueResource;
use Braseidon\VaalApi\Resources\CharacterResource;
use Braseidon\VaalApi\Resources\CurrencyExchangeResource;
use Braseidon\VaalApi\Resources\GuildResource;
use Braseidon\VaalApi\Resources\ItemFilterResource;
use Braseidon\VaalApi\Resources\LeagueAccountResource;
use Braseidon\VaalApi\Resources\LeagueResource;
use Braseidon\VaalApi\Resources\ProfileResource;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use Braseidon\VaalApi\Resources\PublicStashTabResource;
use Braseidon\VaalApi\Resources\PvpMatchResource;
use Braseidon\VaalApi\Resources\StashResource;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the GGG Path of Exile API.
 *
 * Handles authentication, rate limiting, user-agent compliance,
 * scope enforcement, and automatic token refresh.
 */
class ApiClient
{
    private GuzzleClient $httpClient;

    private RateLimiter $rateLimiter;

    private ?Token $token = null;

    private ?Closure $onTokenRefresh = null;

    private ?Closure $onTokenRefreshFailure = null;

    private ?Closure $tokenRefreshGuard = null;

    private ?PathOfExileProvider $authProvider = null;

    /** @var array|null Rate limit headers from the last API response */
    private ?array $lastRateLimitHeaders = null;

    private const MAX_RETRIES = 3;

    /** Seconds a 429 reports when neither Retry-After nor the limiter gives a wait. */
    private const DEFAULT_429_WAIT = 60;

    /** Guzzle request option: skip the 429/503 retry middleware for this request. */
    private const NO_RETRY_OPTION = 'vaal_api_no_retry';

    /**
     * @param array{
     *     client_id?: string,
     *     client_secret?: string,
     *     redirect_uri?: string,
     *     scopes?: string[],
     *     user_agent?: array{version?: string, contact?: string},
     *     rate_limit?: array{strategy?: string, safety_margin?: float, callback?: Closure, auto_retry?: bool, max_retries?: int, store?: RateLimitStore, clock?: Closure},
     *     timeout?: int,
     *     connect_timeout?: int,
     *     default_realm?: string|null,
     *     base_url?: string,
     *     public_url?: string,
     *     logger?: LoggerInterface,
     *     on_response?: (Closure(string, int, array<string, list<string>>, float): void)|null,
     * } $config
     *
     * `on_response` is called once per HTTP response the client receives, with
     * the request path (no host, no query string), the status, the response
     * headers and that attempt's transfer time in seconds. Every status counts,
     * and so does each attempt the retry middleware sends; a request that got
     * no response (connection error, timeout) does not call it. Anything the
     * closure throws is caught and logged as a warning when a `logger` is
     * configured. A set `on_response` that is not a Closure throws
     * InvalidArgumentException. Clients from `public()` and token refresh
     * requests do not call it.
     *
     * @throws \InvalidArgumentException When `on_response` is set but is not a Closure
     */
    public function __construct(
        private readonly array $config = [],
    ) {
        $safetyMargin = $this->config['rate_limit']['safety_margin'] ?? 0.2;
        // `clock` (Unix seconds, int or float) exists for tests that fake time.
        $this->rateLimiter = new RateLimiter(
            $safetyMargin,
            $this->config['rate_limit']['store'] ?? null,
            $this->config['rate_limit']['clock'] ?? null,
        );

        $stack = HandlerStack::create();

        if ($this->config['rate_limit']['auto_retry'] ?? true) {
            $stack->push($this->buildRetryMiddleware(), 'retry_429');
        }

        // Timeouts must stay well under PHP's max_execution_time (typically 30s)
        // so a hung GGG request raises a catchable ConnectionException instead
        // of a fatal error.
        $this->httpClient = new GuzzleClient([
            'handler' => $stack,
            'base_uri' => $this->config['base_url'] ?? 'https://api.pathofexile.com',
            'timeout' => $this->config['timeout'] ?? 12,
            'connect_timeout' => $this->config['connect_timeout'] ?? 5,
            'http_errors' => false,
            'on_stats' => $this->buildResponseHook(),
        ]);
    }

    /**
     * The `on_stats` request option that hands each response to `on_response`.
     *
     * Set once in the Guzzle client's defaults, so it reaches every request:
     * get() and post(), each getMany() pool request, and every attempt the
     * retry middleware sends (Guzzle fires `on_stats` per transfer). Null when
     * no `on_response` is configured, which leaves the option unset.
     */
    private function buildResponseHook(): ?Closure
    {
        $onResponse = $this->config['on_response'] ?? null;

        if ($onResponse === null) {
            return null;
        }

        if (! $onResponse instanceof Closure) {
            throw new \InvalidArgumentException('The on_response config value must be a Closure, got '.get_debug_type($onResponse));
        }

        $logger = $this->config['logger'] ?? null;

        return static function (TransferStats $stats) use ($onResponse, $logger): void {
            $response = $stats->getResponse();

            if ($response === null) {
                return;
            }

            try {
                $onResponse(
                    $stats->getEffectiveUri()->getPath(),
                    $response->getStatusCode(),
                    $response->getHeaders(),
                    (float) ($stats->getTransferTime() ?? 0.0),
                );
            } catch (\Throwable $e) {
                // A failing listener must not break the request or the batch,
                // but it is not silent either
                if ($logger instanceof LoggerInterface) {
                    $logger->warning('on_response hook threw', [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'path' => $stats->getEffectiveUri()->getPath(),
                    ]);
                }
            }
        };
    }

    // ---------------------------------------------------------------
    // Token & Callbacks
    // ---------------------------------------------------------------

    /**
     * Set the OAuth token for authenticated requests.
     *
     * @param  Token  $token  The OAuth token to use
     */
    public function withToken(Token $token): self
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Register a callback for when the token is automatically refreshed.
     *
     * Essential for persisting new tokens. The callback receives the
     * new Token after a successful refresh. The old refresh token is
     * immediately invalidated by GGG.
     *
     * @param  Closure(Token): void  $callback
     */
    public function onTokenRefresh(Closure $callback): self
    {
        $this->onTokenRefresh = $callback;

        return $this;
    }

    /**
     * Register a callback for when a token refresh is rejected.
     *
     * Receives the ORIGINAL exception, before it is wrapped in an
     * AuthenticationException, so the caller can read the provider's status
     * code and response body. An IdentityProviderException carries GGG's HTTP
     * status as its exception code.
     *
     * The callback's own exceptions are suppressed: a failing listener must not
     * replace the refresh failure the caller is about to be told about.
     *
     * @param  Closure(\Exception): void  $callback
     */
    public function onTokenRefreshFailure(Closure $callback): self
    {
        $this->onTokenRefreshFailure = $callback;

        return $this;
    }

    /**
     * Run every token refresh, automatic or explicit, through a guard.
     *
     * GGG's refresh tokens are single use, so two processes that share one
     * account must not refresh at the same time: the second one spends a
     * refresh token GGG has already expired. The guard can hold a lock around
     * the refresh and, once it holds it, return a token another process
     * already stored instead of refreshing again (set it with withToken()
     * first when the refresh should start from it). The client uses whatever
     * token the guard returns.
     *
     * @param  Closure(Closure(): Token): Token  $guard  Receives the refresh and returns the token to use
     */
    public function guardTokenRefresh(Closure $guard): self
    {
        $this->tokenRefreshGuard = $guard;

        return $this;
    }

    // ---------------------------------------------------------------
    // Resource Accessors
    // ---------------------------------------------------------------

    /**
     * Account profile endpoint.
     */
    public function profile(): ProfileResource
    {
        return new ProfileResource($this);
    }

    /**
     * Character endpoints (list and detail).
     *
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function characters(?Realm $realm = null): CharacterResource
    {
        return new CharacterResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Stash tab endpoints for a specific league.
     *
     * @param  string  $league  League name (e.g. "Standard", "Mirage")
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function stashes(string $league, ?Realm $realm = null): StashResource
    {
        return new StashResource($this, $league, $realm ?? $this->defaultRealm());
    }

    /**
     * Service league endpoints (list, detail, ladder).
     */
    public function leagues(): LeagueResource
    {
        return new LeagueResource($this);
    }

    /**
     * Account league endpoints (includes private leagues).
     *
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function accountLeagues(?Realm $realm = null): AccountLeagueResource
    {
        return new AccountLeagueResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Item filter endpoints (list, get, create, update).
     */
    public function itemFilters(): ItemFilterResource
    {
        return new ItemFilterResource($this);
    }

    /**
     * League account endpoint (atlas passives).
     *
     * @param  string  $league  League name
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function leagueAccount(string $league, ?Realm $realm = null): LeagueAccountResource
    {
        return new LeagueAccountResource($this, $league, $realm ?? $this->defaultRealm());
    }

    /**
     * PvP match endpoints.
     */
    public function pvpMatches(): PvpMatchResource
    {
        return new PvpMatchResource($this);
    }

    /**
     * Guild endpoints (stash tabs).
     *
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function guild(?Realm $realm = null): GuildResource
    {
        return new GuildResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Public stash tabs endpoint (OAuth version, service:psapi scope).
     *
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function publicStashTabs(?Realm $realm = null): PublicStashTabResource
    {
        return new PublicStashTabResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Currency exchange endpoint.
     *
     * @param  Realm|null  $realm  Game realm (null defaults to PC)
     */
    public function currencyExchange(?Realm $realm = null): CurrencyExchangeResource
    {
        return new CurrencyExchangeResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Public API client (no auth required, different base URL).
     */
    public function public(): PublicApiClient
    {
        return new PublicApiClient($this->config);
    }

    // ---------------------------------------------------------------
    // Scope Enforcement
    // ---------------------------------------------------------------

    /**
     * Verify the current token has the required scope.
     *
     * Called by resource classes before making requests. Throws early
     * with a clear message instead of letting GGG return a cryptic 403.
     *
     * @param  Scope  $scope  Required scope
     * @param  string  $resourceName  Resource class name for the error message
     *
     * @throws AuthenticationException If no token is set or scope is missing
     */
    public function requireScope(Scope $scope, string $resourceName): void
    {
        if ($this->token === null) {
            throw new AuthenticationException(
                "{$resourceName} requires authentication. Call withToken() first.",
            );
        }

        if (! $this->token->hasScope($scope)) {
            throw new AuthenticationException(sprintf(
                "%s requires scope '%s'. Token has: %s",
                $resourceName,
                $scope->value,
                $this->token->scope ?: '(none)',
            ));
        }
    }

    // ---------------------------------------------------------------
    // HTTP Methods (used by resource classes)
    // ---------------------------------------------------------------

    /**
     * Make an authenticated GET request.
     *
     * Each response calls the `on_response` hook, a 429 the retry middleware
     * retries included.
     *
     * @param  string  $path  API path (e.g. "/profile", "/character")
     * @param  array  $query  Query parameters
     *
     * @throws VaalApiException
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * Make several authenticated GET requests, sending as many at once as the
     * rate limit window allows.
     *
     * Requests go out in rounds through one Guzzle pool (curl_multi, this
     * process). Each round is sized to the hits left in every window of the
     * policy (RateLimiter::capacity()), so no round can overfill a window; the
     * limiter is re-read between rounds and every response is recorded into
     * it as it lands. When no hit is left, the configured strategy waits
     * (sleep / callback) or refuses (exception) exactly as for get().
     *
     * Until a response has told the limiter the policy for these paths, one
     * request goes out alone: with the limits unknown, the only safe round is
     * a single request.
     *
     * Every request of a round is in flight before the first response lands,
     * so a 429 cannot recall the rest of its round; it stops the batch from
     * sending anything more until its wait is over. The retry middleware does
     * not retry inside a round, the 429 is recorded (its Retry-After and
     * penalty become a hard wait), and its key goes back to the queue for the
     * next round, which starts only after that wait. A 429 that carries neither Retry-After nor
     * an active penalty is treated as the policy's longest penalty; one with
     * no rate limit headers at all ends the batch. After `max_retries` rounds
     * that drew a 429 the remaining keys fail.
     *
     * Paths should share one rate limit policy (stash tab reads, for example).
     *
     * Every response of every round calls the `on_response` hook, so a key
     * that drew a 429 and then a 200 reports both.
     *
     * @param  array<array-key, string>  $paths  API paths, keyed as the caller wants results keyed
     * @param  (Closure(array-key, ApiResponse|VaalApiException): void)|null  $onResult  Called as each key settles
     * @return BatchResult<ApiResponse>
     */
    public function getMany(array $paths, ?Closure $onResult = null): BatchResult
    {
        $pending = $paths;
        $responses = [];
        $failures = [];
        $roundsRateLimited = 0;
        $maxRetries = $this->config['rate_limit']['max_retries'] ?? self::MAX_RETRIES;

        $settle = function (int|string $key, ApiResponse|VaalApiException $result) use (&$pending, &$responses, &$failures, $onResult): void {
            unset($pending[$key]);

            if ($result instanceof ApiResponse) {
                $responses[$key] = $result;
            } else {
                $failures[$key] = $result;
            }

            if ($onResult !== null) {
                $onResult($key, $result);
            }
        };

        $fail = function (array $keys, VaalApiException $e) use ($settle): void {
            foreach ($keys as $key) {
                $settle($key, $e);
            }
        };

        while ($pending !== []) {
            try {
                $this->refreshTokenIfNeeded();
                $size = $this->roundSize($pending);
            } catch (VaalApiException $e) {
                $fail(array_keys($pending), $e);
                break;
            }

            $outcome = $this->sendRound(array_slice($pending, 0, $size, true), $settle);

            if ($outcome['abort'] !== null) {
                $fail(array_keys($pending), $outcome['abort']);
                break;
            }

            if ($outcome['rateLimited'] !== null && ++$roundsRateLimited > $maxRetries) {
                $fail(array_keys($pending), $outcome['rateLimited']);
                break;
            }
        }

        return new BatchResult(
            self::inKeyOrder($responses, $paths),
            self::inKeyOrder($failures, $paths),
        );
    }

    /**
     * Make an authenticated POST request with JSON body.
     *
     * @param  string  $path  API path
     * @param  array  $data  JSON body data
     * @param  array  $query  Query parameters
     *
     * @throws VaalApiException
     */
    public function post(string $path, array $data = [], array $query = []): ApiResponse
    {
        $options = ['json' => $data];

        if (! empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('POST', $path, $options);
    }

    // ---------------------------------------------------------------
    // OAuth
    // ---------------------------------------------------------------

    /**
     * Get the OAuth provider for authorization flows.
     */
    public function getAuthProvider(): PathOfExileProvider
    {
        if ($this->authProvider === null) {
            $this->authProvider = new PathOfExileProvider([
                'clientId' => $this->config['client_id'] ?? '',
                'clientSecret' => $this->config['client_secret'] ?? '',
                'redirectUri' => $this->config['redirect_uri'] ?? '',
                'userAgentVersion' => $this->config['user_agent']['version'] ?? '1.0.0',
                'userAgentContact' => $this->config['user_agent']['contact'] ?? '',
            ]);
        }

        return $this->authProvider;
    }

    /**
     * Refresh the current token and return the new one.
     *
     * Triggers the onTokenRefresh callback on success, or the
     * onTokenRefreshFailure callback when the provider rejects the refresh.
     * A guard set with guardTokenRefresh() runs around it.
     *
     * @return Token The new token
     *
     * @throws AuthenticationException If no token is set or refresh fails
     */
    public function refreshToken(): Token
    {
        if ($this->tokenRefreshGuard === null) {
            return $this->sendTokenRefresh();
        }

        $this->token = ($this->tokenRefreshGuard)(fn (): Token => $this->sendTokenRefresh());

        return $this->token;
    }

    /**
     * Spend the current refresh token at GGG for a new token pair.
     *
     * @throws AuthenticationException If no token is set or refresh fails
     */
    private function sendTokenRefresh(): Token
    {
        if ($this->token === null) {
            throw new AuthenticationException('No token set for refresh');
        }

        try {
            $provider = $this->getAuthProvider();
            $newAccessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->token->refreshToken,
            ]);

            $newToken = Token::fromAccessToken($newAccessToken);
            $this->token = $newToken;

            if ($this->onTokenRefresh !== null) {
                ($this->onTokenRefresh)($newToken);
            }

            return $newToken;
        } catch (\Exception $e) {
            if ($this->onTokenRefreshFailure !== null) {
                try {
                    ($this->onTokenRefreshFailure)($e);
                } catch (\Throwable) {
                    // A failing listener must not mask the refresh failure
                }
            }

            throw new AuthenticationException(
                'Token refresh failed: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Get the rate limiter instance.
     */
    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Get the current token, if set.
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Get rate limit headers from the last API response.
     *
     * Returns a flat array of GGG rate limit headers (policy, rules, state),
     * or null if the last response had no rate limit headers.
     *
     * @return array<string, string>|null
     */
    public function getLastRateLimitHeaders(): ?array
    {
        return $this->lastRateLimitHeaders;
    }

    // ---------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------

    /**
     * Execute an HTTP request with rate limiting, auth, and error handling.
     *
     * 429 retry is handled by Guzzle middleware (see buildRetryMiddleware).
     * Pre-flight rate limiting prevents most 429s; middleware catches the rest.
     *
     * @param  string  $method  HTTP method
     * @param  string  $path  API path
     * @param  array  $options  Guzzle request options
     *
     * @throws VaalApiException
     */
    private function request(string $method, string $path, array $options = []): ApiResponse
    {
        $this->refreshTokenIfNeeded();

        // Pre-flight rate limit check
        $policy = $this->guessPolicyForPath($path);
        if ($policy !== '') {
            $check = $this->rateLimiter->check($policy);
            if (! $check->canProceed) {
                $this->handleRateLimit($check);
            }
        }

        // Build headers
        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            $this->buildHeaders(),
        );

        try {
            $response = new ApiResponse(
                $this->httpClient->request($method, ltrim($path, '/'), $options)
            );
        } catch (TransferException $e) {
            // Never got a response — connect/read timeout or network failure.
            throw new ConnectionException(
                'GGG API did not respond: '.$e->getMessage(),
                previous: $e,
            );
        }

        $this->recordRateLimit($response, $path);

        // Handle error responses (429s already retried by middleware)
        if (! $response->isSuccessful()) {
            throw $this->errorFor($response, $this->policyOf($response, $path));
        }

        return $response;
    }

    /**
     * Record a response's rate limit state, and which policy its path is under.
     */
    private function recordRateLimit(ApiResponse $response, string $path): void
    {
        $rateLimitPolicy = $response->rateLimitPolicy();

        if ($rateLimitPolicy === null) {
            $this->lastRateLimitHeaders = null;

            return;
        }

        $responseHeaders = $response->raw()->getHeaders();
        $this->rateLimiter->recordResponse($responseHeaders);
        $this->rateLimiter->rememberPolicyForPath($this->normalizePathForPolicy($path), $rateLimitPolicy->name);

        // Store rate limit headers for external consumers
        $this->lastRateLimitHeaders = $this->extractRateLimitHeaders($responseHeaders);
    }

    /**
     * How many of the pending paths the next round may send.
     *
     * Waits through the configured strategy first when no hit is left, so a
     * RateLimitException from the exception strategy surfaces here.
     *
     * @param  array<array-key, string>  $pending
     *
     * @throws RateLimitException
     */
    private function roundSize(array $pending): int
    {
        $policies = [];

        foreach ($pending as $path) {
            $policy = $this->guessPolicyForPath($path);

            // Limits unknown until a response names the policy: one request alone.
            if ($policy === '') {
                return 1;
            }

            $policies[$policy] = true;
        }

        $policies = array_keys($policies);
        $capacity = $this->capacityFor($policies);

        $waits = 0;
        $maxWaits = 1 + ($this->config['rate_limit']['max_retries'] ?? self::MAX_RETRIES);

        // Re-read after every wait: during it another process may have taken
        // the freed slot, or a restriction may have moved later, so a full
        // wait that still leaves no room waits again. A strategy that returned
        // without waiting the whole time (log, or a callback that did not
        // sleep), or a window still full after max_retries more waits, gets
        // what get() does after the same strategy: one request.
        while ($capacity === 0 && $waits++ < $maxWaits && ($wait = $this->longestWait($policies)) !== null) {
            $before = $this->now();
            $this->handleRateLimit($wait);
            $capacity = $this->capacityFor($policies);

            if ($this->now() - $before < $wait->waitSeconds) {
                break;
            }
        }

        return min(max(1, $capacity), count($pending));
    }

    /**
     * The current Unix time in seconds, on the clock the rate limiter reads.
     */
    private function now(): float
    {
        $clock = $this->config['rate_limit']['clock'] ?? null;

        return $clock instanceof Closure ? (float) $clock() : microtime(true);
    }

    /**
     * Hits left across these policies; a policy with no figure yet allows one.
     *
     * @param  list<string>  $policies
     */
    private function capacityFor(array $policies): int
    {
        $capacity = PHP_INT_MAX;

        foreach ($policies as $policy) {
            $capacity = min($capacity, $this->rateLimiter->capacity($policy) ?? 1);
        }

        return $capacity;
    }

    /**
     * The longest pre-flight wait across these policies, or null when none must wait.
     *
     * @param  list<string>  $policies
     */
    private function longestWait(array $policies): ?RateLimitResult
    {
        $longest = null;

        foreach ($policies as $policy) {
            $check = $this->rateLimiter->check($policy);

            if (! $check->canProceed && ($longest === null || $check->waitSeconds > $longest->waitSeconds)) {
                $longest = $check;
            }
        }

        return $longest;
    }

    /**
     * Send one round concurrently, settling each key as its response lands.
     *
     * The pool's concurrency is the round's size, so every request is handed
     * to curl_multi before the first response is read: nothing in the round
     * can be held back once one response comes back a 429.
     *
     * A key that drew a 429 is not settled, so it stays queued for the next
     * round, unless the 429 gave nothing to wait on, which ends the batch
     * (`abort`).
     *
     * @param  array<array-key, string>  $round
     * @param  Closure(array-key, ApiResponse|VaalApiException): void  $settle
     * @return array{rateLimited: VaalApiException|null, abort: VaalApiException|null}
     */
    private function sendRound(array $round, Closure $settle): array
    {
        $headers = $this->buildHeaders();
        $rateLimited = null;
        $abort = null;

        $requests = function () use ($round, $headers): \Generator {
            foreach ($round as $key => $path) {
                yield $key => fn () => $this->httpClient->requestAsync('GET', ltrim($path, '/'), [
                    'headers' => $headers,
                    self::NO_RETRY_OPTION => true,
                ]);
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => max(1, count($round)),
            'fulfilled' => function (ResponseInterface $raw, int|string $key) use ($round, $settle, &$rateLimited, &$abort): void {
                $response = new ApiResponse($raw);
                $path = $round[$key];
                $this->recordRateLimit($response, $path);

                if ($response->status() !== 429) {
                    $settle($key, $response->isSuccessful() ? $response : $this->errorFor($response, $this->policyOf($response, $path)));

                    return;
                }

                // Restrict first, so the error carries the wait the store now holds.
                $restricted = $this->restrictAfter429($response);
                $error = $this->errorFor($response, $this->policyOf($response, $path));

                if ($restricted) {
                    $rateLimited ??= $error;
                } else {
                    $settle($key, $error);
                    $abort ??= $error;
                }
            },
            'rejected' => function (mixed $reason, int|string $key) use ($settle): void {
                $settle($key, match (true) {
                    $reason instanceof TransferException => new ConnectionException('GGG API did not respond: '.$reason->getMessage(), previous: $reason),
                    $reason instanceof VaalApiException => $reason,
                    $reason instanceof \Throwable => new VaalApiException($reason->getMessage(), 0, $reason),
                    default => new VaalApiException('GGG API request failed'),
                });
            },
        ]);

        $pool->promise()->wait();

        return ['rateLimited' => $rateLimited, 'abort' => $abort];
    }

    /**
     * Make sure a recorded 429 leaves a wait behind before anything else goes out.
     *
     * GGG's 429 carries Retry-After and an active penalty, which
     * recordResponse() has already turned into a hard wait. A 429 without
     * either gets the strictest rule its headers name: the policy's longest
     * penalty. A 429 with no rate limit headers leaves nothing to wait on.
     *
     * @return bool False when there is no wait to honour and the batch must stop
     */
    private function restrictAfter429(ApiResponse $response): bool
    {
        $policy = $response->rateLimitPolicy();

        if ($policy === null) {
            return false;
        }

        $longestPenalty = 0;

        foreach ($policy->rules as $windows) {
            foreach ($windows as $window) {
                if ($window->activePenalty > 0) {
                    return true;
                }

                $longestPenalty = max($longestPenalty, $window->penalty);
            }
        }

        if (($policy->retryAfter ?? 0) > 0) {
            return true;
        }

        if ($longestPenalty === 0) {
            return false;
        }

        $this->rateLimiter->restrict(
            $policy->name,
            $longestPenalty,
            "429 without Retry-After: waiting the policy's longest penalty ({$longestPenalty}s)",
        );

        return true;
    }

    /**
     * Results in the order the caller keyed the batch.
     *
     * @template T
     *
     * @param  array<array-key, T>  $results
     * @param  array<array-key, string>  $paths
     * @return array<array-key, T>
     */
    private static function inKeyOrder(array $results, array $paths): array
    {
        $ordered = [];

        foreach (array_keys($paths) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * Automatically refresh the token if it's about to expire.
     *
     *
     * @throws AuthenticationException
     */
    private function refreshTokenIfNeeded(): void
    {
        if ($this->token === null || ! $this->token->needsRefresh()) {
            return;
        }

        if (empty($this->token->refreshToken)) {
            throw new AuthenticationException(
                'Access token '.($this->token->isExpired() ? 'expired' : 'expiring').' and no refresh token available'
            );
        }

        $this->refreshToken();
    }

    /**
     * Build request headers with auth and user-agent.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'User-Agent' => $this->buildUserAgent(),
            'Accept' => 'application/json',
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer '.$this->token->accessToken;
        }

        return $headers;
    }

    /**
     * Build the User-Agent string per GGG requirements.
     *
     * Format: OAuth {clientId}/{version} (contact: {email})
     */
    private function buildUserAgent(): string
    {
        $clientId = $this->config['client_id'] ?? 'unknown';
        $version = $this->config['user_agent']['version'] ?? '1.0.0';
        $contact = $this->config['user_agent']['contact'] ?? '';

        $ua = "OAuth {$clientId}/{$version}";

        if ($contact !== '') {
            $ua .= " (contact: {$contact})";
        }

        return $ua;
    }

    /**
     * Build Guzzle retry middleware for 429/503 responses.
     *
     * Sleeps for the duration specified by Retry-After, then retries.
     * Falls back to exponential backoff if no Retry-After header (Guzzle
     * counts retries from 1, so 2s, 4s, 8s).
     *
     * A request sent with the NO_RETRY_OPTION option skips it: a getMany()
     * round handles its own 429s, and a retry inside the pool would resend
     * while the rest of the round is still in flight.
     */
    private function buildRetryMiddleware(): callable
    {
        $retry = $this->buildRetryDecider();

        return static function (callable $handler) use ($retry): callable {
            $retrying = $retry($handler);

            return static fn (RequestInterface $request, array $options) => ($options[self::NO_RETRY_OPTION] ?? false)
                ? $handler($request, $options)
                : $retrying($request, $options);
        };
    }

    private function buildRetryDecider(): callable
    {
        $maxRetries = $this->config['rate_limit']['max_retries'] ?? self::MAX_RETRIES;

        return Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response = null) use ($maxRetries): bool {
                if ($retries >= $maxRetries) {
                    return false;
                }

                if ($response === null) {
                    return false;
                }

                if ($response->getStatusCode() === 429) {
                    // Record the lockout before this process waits it out, so
                    // clients sharing the store stop instead of piling on.
                    $this->rateLimiter->recordResponse($response->getHeaders());
                }

                return in_array($response->getStatusCode(), [429, 503], true);
            },
            function (int $retries, ResponseInterface $response): int {
                if ($response->hasHeader('Retry-After')) {
                    return (int) $response->getHeaderLine('Retry-After') * 1000;
                }

                // Exponential backoff: 2s, 4s, 8s (Guzzle passes retries from 1)
                return 1000 * (2 ** $retries);
            },
        );
    }

    /**
     * Apply the configured rate limit strategy for pre-flight checks.
     *
     * Called when the RateLimiter predicts we're about to exceed a limit.
     * This prevents 429s; the retry middleware handles any that slip through.
     *
     * @param  RateLimitResult  $result  The rate limit check result
     *
     * @throws RateLimitException
     */
    private function handleRateLimit(RateLimitResult $result): void
    {
        $strategyValue = $this->config['rate_limit']['strategy'] ?? 'sleep';
        $strategy = is_string($strategyValue)
            ? RateLimitStrategy::from($strategyValue)
            : $strategyValue;

        match ($strategy) {
            RateLimitStrategy::Sleep => sleep($result->waitSeconds),
            RateLimitStrategy::Exception => throw new RateLimitException($result),
            RateLimitStrategy::Callback => $this->invokeRateLimitCallback($result),
            RateLimitStrategy::Log => $this->logRateLimit($result),
        };
    }

    /**
     * Invoke the user-provided rate limit callback.
     *
     * @param  RateLimitResult  $result  The rate limit check result
     */
    private function invokeRateLimitCallback(RateLimitResult $result): void
    {
        $callback = $this->config['rate_limit']['callback'] ?? null;

        if ($callback instanceof Closure) {
            $callback($result);
        }
    }

    /**
     * Log a rate limit warning via the configured PSR-3 logger.
     *
     * @param  RateLimitResult  $result  The rate limit check result
     */
    private function logRateLimit(RateLimitResult $result): void
    {
        $logger = $this->config['logger'] ?? null;

        if ($logger instanceof LoggerInterface) {
            $logger->warning('Rate limit approaching', [
                'policy' => $result->policy,
                'wait_seconds' => $result->waitSeconds,
                'reason' => $result->reason,
            ]);
        }
    }

    /**
     * The exception a non-2xx response stands for.
     *
     * 429 responses that reach here have already exhausted retry middleware
     * (or middleware is disabled, or the request was part of a getMany()
     * round). They become RateLimitExceptions.
     *
     * @param  ApiResponse  $response  The API response
     * @param  string  $policy  The rate limit policy name
     */
    private function errorFor(ApiResponse $response, string $policy): VaalApiException
    {
        $status = $response->status();
        $data = $response->data();
        $message = $response->errorMessage("HTTP {$status}");

        $rlHeaders = $response->rateLimitPolicy() === null
            ? null
            : $this->extractRateLimitHeaders($response->raw()->getHeaders());

        return match (true) {
            $status === 429 => new RateLimitException(
                RateLimitResult::wait($policy, $this->retryAfterFor($response, $policy), $message),
                responseBody: $data,
                rateLimitHeaders: $rlHeaders,
            ),
            $status === 401, $status === 403 => new AuthenticationException($message, $status, responseBody: $data, rateLimitHeaders: $rlHeaders),
            $status === 404 => new ResourceNotFoundException($message, $status, responseBody: $data, rateLimitHeaders: $rlHeaders),
            $status >= 400 && $status < 500 => new InvalidRequestException($message, $status, responseBody: $data, rateLimitHeaders: $rlHeaders),
            $status >= 500 => new ServerException($message, $status, responseBody: $data, rateLimitHeaders: $rlHeaders),
            default => new VaalApiException($message, $status, responseBody: $data, rateLimitHeaders: $rlHeaders),
        };
    }

    /**
     * The policy a response names, else the one its path was last seen under.
     */
    private function policyOf(ApiResponse $response, string $path): string
    {
        $policy = $response->rateLimitPolicy();

        return $policy !== null ? $policy->name : $this->guessPolicyForPath($path);
    }

    /**
     * Seconds a 429's RateLimitException tells the caller to wait: the longer
     * of GGG's Retry-After and the wait the limiter now holds for the policy
     * (a penalty, or the longest penalty restrict() stored for a 429 that
     * stated no wait), so the figure matches what the next pre-flight check
     * enforces. DEFAULT_429_WAIT when neither says anything.
     */
    private function retryAfterFor(ApiResponse $response, string $policy): int
    {
        $stated = $response->header('Retry-After');
        $held = $policy === '' ? 0 : $this->rateLimiter->check($policy)->waitSeconds;

        if ($stated === null && $held === 0) {
            return self::DEFAULT_429_WAIT;
        }

        return max((int) $stated, $held);
    }

    /**
     * Guess the rate limit policy for a path based on previous responses.
     *
     * @param  string  $path  API path
     * @return string Policy name, or empty string if unknown
     */
    private function guessPolicyForPath(string $path): string
    {
        return $this->rateLimiter->policyForPath($this->normalizePathForPolicy($path));
    }

    /**
     * Normalize a path to a pattern for policy mapping.
     *
     * Strips specific IDs/names to group endpoints:
     *   /character/pc/SomeName -> /character/{name}
     *   /stash/Mirage/abc123  -> /stash/{league}/{id}
     *
     * @param  string  $path  API path
     * @return string Normalized path pattern
     */
    private function normalizePathForPolicy(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        // The realm segment is optional, so it is matched by name: a generic
        // segment would read "/character/{name}" as the list path with a realm.
        // A realm-less read of a character named exactly like a realm ("pc",
        // "xbox", "sony", "poe2") still reads as the list path: accepted, as
        // GGG's lowercase realm names make that collision unlikely.
        $realm = '(/(?:'.implode('|', array_map(fn (Realm $r) => $r->value, Realm::cases())).'))?';

        $patterns = [
            "#^/character{$realm}$#" => '/character',
            "#^/character{$realm}/.+$#" => '/character/{name}',
            "#^/stash{$realm}/[^/]+$#" => '/stash/{league}',
            "#^/stash{$realm}/[^/]+/.+$#" => '/stash/{league}/{id}',
            '#^/account/leagues#' => '/account/leagues',
            '#^/league-account#' => '/league-account',
            '#^/league/[^/]+/ladder$#' => '/league/{id}/ladder',
            '#^/league/[^/]+/event-ladder$#' => '/league/{id}/event-ladder',
            '#^/league/.+$#' => '/league/{id}',
            '#^/league$#' => '/league',
            '#^/item-filter/.+$#' => '/item-filter/{id}',
            '#^/item-filter$#' => '/item-filter',
            '#^/pvp-match/[^/]+/ladder$#' => '/pvp-match/{id}/ladder',
            '#^/pvp-match/.+$#' => '/pvp-match/{id}',
            '#^/pvp-match$#' => '/pvp-match',
            '#^/guild(/\w+)?/stash/[^/]+/.+$#' => '/guild/stash/{league}/{id}',
            '#^/guild(/\w+)?/stash/.+$#' => '/guild/stash/{league}',
            '#^/public-stash-tabs#' => '/public-stash-tabs',
            '#^/currency-exchange#' => '/currency-exchange',
            '#^/profile$#' => '/profile',
        ];

        foreach ($patterns as $pattern => $normalized) {
            if (preg_match($pattern, $path)) {
                return $normalized;
            }
        }

        return $path;
    }

    /**
     * Extract rate limit headers from a response.
     *
     * Filters to only X-Rate-Limit-* and Retry-After headers.
     *
     * @param  array  $headers  All response headers
     * @return array<string, string> Rate limit headers with string values
     */
    private function extractRateLimitHeaders(array $headers): array
    {
        $rateLimitHeaders = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);

            if (str_starts_with($lower, 'x-rate-limit') || $lower === 'retry-after') {
                $rateLimitHeaders[$name] = is_array($values) ? implode(',', $values) : (string) $values;
            }
        }

        return $rateLimitHeaders;
    }

    /**
     * Get the default realm from config.
     */
    private function defaultRealm(): ?Realm
    {
        $realm = $this->config['default_realm'] ?? null;

        if ($realm === null) {
            return null;
        }

        return $realm instanceof Realm ? $realm : Realm::from($realm);
    }
}
