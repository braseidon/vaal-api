<?php

namespace Braseidon\VaalApi\Client;

use Braseidon\VaalApi\Auth\PathOfExileProvider;
use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\RateLimitStrategy;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\RateLimit\RateLimiter;
use Braseidon\VaalApi\RateLimit\RateLimitResult;
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
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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
    private GuzzleClient        $httpClient;
    private RateLimiter         $rateLimiter;
    private ?Token              $token          = null;
    private ?Closure            $onTokenRefresh = null;
    private ?PathOfExileProvider $authProvider   = null;

    /** @var array<string, string> Maps URL patterns to known policy names */
    private array $policyMap = [];

    private const MAX_RETRIES = 3;

    /**
     * @param array{
     *     client_id?: string,
     *     client_secret?: string,
     *     redirect_uri?: string,
     *     scopes?: string[],
     *     user_agent?: array{version?: string, contact?: string},
     *     rate_limit?: array{strategy?: string, safety_margin?: float, callback?: Closure, auto_retry?: bool, max_retries?: int},
     *     timeout?: int,
     *     default_realm?: string|null,
     *     base_url?: string,
     *     public_url?: string,
     *     logger?: LoggerInterface,
     * } $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {
        $safetyMargin    = $this->config['rate_limit']['safety_margin'] ?? 0.2;
        $this->rateLimiter = new RateLimiter($safetyMargin);

        $stack = HandlerStack::create();

        if ($this->config['rate_limit']['auto_retry'] ?? true) {
            $stack->push($this->buildRetryMiddleware(), 'retry_429');
        }

        $this->httpClient = new GuzzleClient([
            'handler'     => $stack,
            'base_uri'    => $this->config['base_url'] ?? 'https://api.pathofexile.com',
            'timeout'     => $this->config['timeout'] ?? 30,
            'http_errors' => false,
        ]);
    }

    // ---------------------------------------------------------------
    // Token & Callbacks
    // ---------------------------------------------------------------

    /**
     * Set the OAuth token for authenticated requests.
     *
     * @param Token $token The OAuth token to use
     * @return self
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
     * @param Closure(Token): void $callback
     * @return self
     */
    public function onTokenRefresh(Closure $callback): self
    {
        $this->onTokenRefresh = $callback;

        return $this;
    }

    // ---------------------------------------------------------------
    // Resource Accessors
    // ---------------------------------------------------------------

    /**
     * Account profile endpoint.
     *
     * @return ProfileResource
     */
    public function profile(): ProfileResource
    {
        return new ProfileResource($this);
    }

    /**
     * Character endpoints (list and detail).
     *
     * @param Realm|null $realm Game realm (null defaults to PC)
     * @return CharacterResource
     */
    public function characters(?Realm $realm = null): CharacterResource
    {
        return new CharacterResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Stash tab endpoints for a specific league.
     *
     * @param string     $league League name (e.g. "Standard", "Mirage")
     * @param Realm|null $realm  Game realm (null defaults to PC)
     * @return StashResource
     */
    public function stashes(string $league, ?Realm $realm = null): StashResource
    {
        return new StashResource($this, $league, $realm ?? $this->defaultRealm());
    }

    /**
     * Service league endpoints (list, detail, ladder).
     *
     * @return LeagueResource
     */
    public function leagues(): LeagueResource
    {
        return new LeagueResource($this);
    }

    /**
     * Account league endpoints (includes private leagues).
     *
     * @param Realm|null $realm Game realm (null defaults to PC)
     * @return AccountLeagueResource
     */
    public function accountLeagues(?Realm $realm = null): AccountLeagueResource
    {
        return new AccountLeagueResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Item filter endpoints (list, get, create, update).
     *
     * @return ItemFilterResource
     */
    public function itemFilters(): ItemFilterResource
    {
        return new ItemFilterResource($this);
    }

    /**
     * League account endpoint (atlas passives).
     *
     * @param string     $league League name
     * @param Realm|null $realm  Game realm (null defaults to PC)
     * @return LeagueAccountResource
     */
    public function leagueAccount(string $league, ?Realm $realm = null): LeagueAccountResource
    {
        return new LeagueAccountResource($this, $league, $realm ?? $this->defaultRealm());
    }

    /**
     * PvP match endpoints.
     *
     * @return PvpMatchResource
     */
    public function pvpMatches(): PvpMatchResource
    {
        return new PvpMatchResource($this);
    }

    /**
     * Guild endpoints (stash tabs).
     *
     * @param Realm|null $realm Game realm (null defaults to PC)
     * @return GuildResource
     */
    public function guild(?Realm $realm = null): GuildResource
    {
        return new GuildResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Public stash tabs endpoint (OAuth version, service:psapi scope).
     *
     * @param Realm|null $realm Game realm (null defaults to PC)
     * @return PublicStashTabResource
     */
    public function publicStashTabs(?Realm $realm = null): PublicStashTabResource
    {
        return new PublicStashTabResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Currency exchange endpoint.
     *
     * @param Realm|null $realm Game realm (null defaults to PC)
     * @return CurrencyExchangeResource
     */
    public function currencyExchange(?Realm $realm = null): CurrencyExchangeResource
    {
        return new CurrencyExchangeResource($this, $realm ?? $this->defaultRealm());
    }

    /**
     * Public API client (no auth required, different base URL).
     *
     * @return PublicApiClient
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
     * @param Scope  $scope        Required scope
     * @param string $resourceName Resource class name for the error message
     * @return void
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

        if (!$this->token->hasScope($scope)) {
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
     * @param string $path  API path (e.g. "/profile", "/character")
     * @param array  $query Query parameters
     * @return ApiResponse
     *
     * @throws VaalApiException
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * Make an authenticated POST request with JSON body.
     *
     * @param string $path  API path
     * @param array  $data  JSON body data
     * @param array  $query Query parameters
     * @return ApiResponse
     *
     * @throws VaalApiException
     */
    public function post(string $path, array $data = [], array $query = []): ApiResponse
    {
        $options = ['json' => $data];

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->request('POST', $path, $options);
    }

    // ---------------------------------------------------------------
    // OAuth
    // ---------------------------------------------------------------

    /**
     * Get the OAuth provider for authorization flows.
     *
     * @return PathOfExileProvider
     */
    public function getAuthProvider(): PathOfExileProvider
    {
        if ($this->authProvider === null) {
            $this->authProvider = new PathOfExileProvider([
                'clientId'     => $this->config['client_id'] ?? '',
                'clientSecret' => $this->config['client_secret'] ?? '',
                'redirectUri'  => $this->config['redirect_uri'] ?? '',
            ]);
        }

        return $this->authProvider;
    }

    /**
     * Refresh the current token and return the new one.
     *
     * Triggers the onTokenRefresh callback if registered.
     *
     * @return Token The new token
     *
     * @throws AuthenticationException If no token is set or refresh fails
     */
    public function refreshToken(): Token
    {
        if ($this->token === null) {
            throw new AuthenticationException('No token set for refresh');
        }

        try {
            $provider       = $this->getAuthProvider();
            $newAccessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->token->refreshToken,
            ]);

            $newToken    = Token::fromAccessToken($newAccessToken);
            $this->token = $newToken;

            if ($this->onTokenRefresh !== null) {
                ($this->onTokenRefresh)($newToken);
            }

            return $newToken;
        } catch (\Exception $e) {
            throw new AuthenticationException(
                'Token refresh failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Get the rate limiter instance.
     *
     * @return RateLimiter
     */
    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Get the current token, if set.
     *
     * @return Token|null
     */
    public function getToken(): ?Token
    {
        return $this->token;
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
     * @param string $method  HTTP method
     * @param string $path    API path
     * @param array  $options Guzzle request options
     * @return ApiResponse
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
            if (!$check->canProceed) {
                $this->handleRateLimit($check);
            }
        }

        // Build headers
        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            $this->buildHeaders(),
        );

        $response = new ApiResponse(
            $this->httpClient->request($method, ltrim($path, '/'), $options)
        );

        // Record rate limit state from response
        $rateLimitPolicy = $response->rateLimitPolicy();
        if ($rateLimitPolicy !== null) {
            $this->rateLimiter->recordResponse($response->raw()->getHeaders());
            $this->policyMap[$this->normalizePathForPolicy($path)] = $rateLimitPolicy->name;
        }

        // Handle error responses (429s already retried by middleware)
        if (!$response->isSuccessful()) {
            $this->handleErrorResponse($response, $policy);
        }

        return $response;
    }

    /**
     * Automatically refresh the token if it's about to expire.
     *
     * @return void
     *
     * @throws AuthenticationException
     */
    private function refreshTokenIfNeeded(): void
    {
        if ($this->token === null || !$this->token->needsRefresh()) {
            return;
        }

        if (empty($this->token->refreshToken)) {
            throw new AuthenticationException(
                'Access token ' . ($this->token->isExpired() ? 'expired' : 'expiring') . ' and no refresh token available'
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
            'Accept'     => 'application/json',
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->token->accessToken;
        }

        return $headers;
    }

    /**
     * Build the User-Agent string per GGG requirements.
     *
     * Format: OAuth {clientId}/{version} (contact: {email})
     *
     * @return string
     */
    private function buildUserAgent(): string
    {
        $clientId = $this->config['client_id'] ?? 'unknown';
        $version  = $this->config['user_agent']['version'] ?? '1.0.0';
        $contact  = $this->config['user_agent']['contact'] ?? '';

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
     * Falls back to exponential backoff if no Retry-After header.
     *
     * @return callable
     */
    private function buildRetryMiddleware(): callable
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

                return in_array($response->getStatusCode(), [429, 503], true);
            },
            function (int $retries, ResponseInterface $response): int {
                if ($response->hasHeader('Retry-After')) {
                    return (int) $response->getHeaderLine('Retry-After') * 1000;
                }

                // Exponential backoff: 1s, 2s, 4s...
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
     * @param RateLimitResult $result The rate limit check result
     * @return void
     *
     * @throws RateLimitException
     */
    private function handleRateLimit(RateLimitResult $result): void
    {
        $strategyValue = $this->config['rate_limit']['strategy'] ?? 'sleep';
        $strategy      = is_string($strategyValue)
            ? RateLimitStrategy::from($strategyValue)
            : $strategyValue;

        match ($strategy) {
            RateLimitStrategy::Sleep     => sleep($result->waitSeconds),
            RateLimitStrategy::Exception => throw new RateLimitException($result),
            RateLimitStrategy::Callback  => $this->invokeRateLimitCallback($result),
            RateLimitStrategy::Log       => $this->logRateLimit($result),
        };
    }

    /**
     * Invoke the user-provided rate limit callback.
     *
     * @param RateLimitResult $result The rate limit check result
     * @return void
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
     * @param RateLimitResult $result The rate limit check result
     * @return void
     */
    private function logRateLimit(RateLimitResult $result): void
    {
        $logger = $this->config['logger'] ?? null;

        if ($logger instanceof LoggerInterface) {
            $logger->warning('Rate limit approaching', [
                'policy'       => $result->policy,
                'wait_seconds' => $result->waitSeconds,
                'reason'       => $result->reason,
            ]);
        }
    }

    /**
     * Handle non-2xx responses with appropriate exceptions.
     *
     * 429 responses that reach here have already exhausted retry middleware
     * (or middleware is disabled). They become RateLimitExceptions.
     *
     * @param ApiResponse $response The API response
     * @param string      $policy   The rate limit policy name
     * @return void
     *
     * @throws VaalApiException
     */
    private function handleErrorResponse(ApiResponse $response, string $policy): void
    {
        $status  = $response->status();
        $data    = $response->data();
        $message = $data['error']['message']
            ?? $data['error']
            ?? $data['message']
            ?? "HTTP {$status}";

        match (true) {
            $status === 429                  => throw new RateLimitException(
                RateLimitResult::wait($policy, (int) ($response->header('Retry-After') ?? 60), $message),
                responseBody: $data,
            ),
            $status === 401, $status === 403 => throw new AuthenticationException($message, $status, responseBody: $data),
            $status === 404                  => throw new ResourceNotFoundException($message, $status, responseBody: $data),
            $status >= 400 && $status < 500  => throw new InvalidRequestException($message, $status, responseBody: $data),
            $status >= 500                   => throw new ServerException($message, $status, responseBody: $data),
            default                          => throw new VaalApiException($message, $status, responseBody: $data),
        };
    }

    /**
     * Guess the rate limit policy for a path based on previous responses.
     *
     * @param string $path API path
     * @return string Policy name, or empty string if unknown
     */
    private function guessPolicyForPath(string $path): string
    {
        $normalized = $this->normalizePathForPolicy($path);

        return $this->policyMap[$normalized] ?? '';
    }

    /**
     * Normalize a path to a pattern for policy mapping.
     *
     * Strips specific IDs/names to group endpoints:
     *   /character/pc/SomeName -> /character/{name}
     *   /stash/Mirage/abc123  -> /stash/{league}/{id}
     *
     * @param string $path API path
     * @return string Normalized path pattern
     */
    private function normalizePathForPolicy(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        $patterns = [
            '#^/character(/\w+)?$#'            => '/character',
            '#^/character(/\w+)?/.+$#'         => '/character/{name}',
            '#^/stash(/\w+)?/[^/]+$#'          => '/stash/{league}',
            '#^/stash(/\w+)?/[^/]+/.+$#'       => '/stash/{league}/{id}',
            '#^/account/leagues#'              => '/account/leagues',
            '#^/league-account#'              => '/league-account',
            '#^/league/[^/]+/ladder$#'         => '/league/{id}/ladder',
            '#^/league/[^/]+/event-ladder$#'   => '/league/{id}/event-ladder',
            '#^/league/.+$#'                  => '/league/{id}',
            '#^/league$#'                     => '/league',
            '#^/item-filter/.+$#'             => '/item-filter/{id}',
            '#^/item-filter$#'                => '/item-filter',
            '#^/pvp-match/[^/]+/ladder$#'      => '/pvp-match/{id}/ladder',
            '#^/pvp-match/.+$#'               => '/pvp-match/{id}',
            '#^/pvp-match$#'                  => '/pvp-match',
            '#^/guild(/\w+)?/stash/[^/]+/.+$#' => '/guild/stash/{league}/{id}',
            '#^/guild(/\w+)?/stash/.+$#'       => '/guild/stash/{league}',
            '#^/public-stash-tabs#'           => '/public-stash-tabs',
            '#^/currency-exchange#'           => '/currency-exchange',
            '#^/profile$#'                    => '/profile',
        ];

        foreach ($patterns as $pattern => $normalized) {
            if (preg_match($pattern, $path)) {
                return $normalized;
            }
        }

        return $path;
    }

    /**
     * Get the default realm from config.
     *
     * @return Realm|null
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
