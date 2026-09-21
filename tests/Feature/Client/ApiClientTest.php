<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\RateLimitException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Resources\CharacterResource;
use Braseidon\VaalApi\Resources\CurrencyExchangeResource;
use Braseidon\VaalApi\Resources\GuildResource;
use Braseidon\VaalApi\Resources\ItemFilterResource;
use Braseidon\VaalApi\Resources\LeagueResource;
use Braseidon\VaalApi\Resources\ProfileResource;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use Braseidon\VaalApi\Resources\PublicStashTabResource;
use Braseidon\VaalApi\Resources\PvpMatchResource;
use Braseidon\VaalApi\Resources\StashResource;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    /**
     * Create an ApiClient with a mocked Guzzle handler.
     *
     * @param  Response[]  $responses  Queued mock responses
     * @param  array  $config  Client config overrides
     */
    private function createClientWithMock(array $responses, array $config = []): ApiClient
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);

        $client = new ApiClient(array_merge([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'safety_margin' => 0.2],
        ], $config));

        // Inject the mocked Guzzle client via reflection
        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler' => $handler,
            'http_errors' => false,
        ]));

        return $client;
    }

    /**
     * Create a token with all scopes and a far-future expiry.
     */
    private function createValidToken(): Token
    {
        return Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]);
    }

    // ---------------------------------------------------------------
    // Successful Requests
    // ---------------------------------------------------------------

    public function test_get_returns_api_response(): void
    {
        $body = json_encode(['uuid' => 'abc-123', 'name' => 'Test#1']);
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ]);
        $client->withToken($this->createValidToken());

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('abc-123', $response->data()['uuid']);
    }

    public function test_post_sends_json_body(): void
    {
        $body = json_encode(['id' => 'filter-1']);
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ]);
        $client->withToken($this->createValidToken());

        $response = $client->post('/item-filter', ['name' => 'Test Filter']);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('filter-1', $response->data()['id']);
    }

    // ---------------------------------------------------------------
    // Scope Enforcement
    // ---------------------------------------------------------------

    public function test_require_scope_throws_without_token(): void
    {
        $client = $this->createClientWithMock([]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('requires authentication');

        $client->requireScope(Scope::Profile, 'ProfileResource');
    }

    public function test_require_scope_throws_for_missing_scope(): void
    {
        $client = $this->createClientWithMock([]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test',
            'scope' => 'account:profile',
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage("requires scope 'account:characters'");

        $client->requireScope(Scope::Characters, 'CharacterResource');
    }

    public function test_require_scope_passes_with_correct_scope(): void
    {
        $client = $this->createClientWithMock([]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test',
            'scope' => 'account:profile account:characters',
        ]));

        // Should not throw
        $client->requireScope(Scope::Profile, 'ProfileResource');
        $client->requireScope(Scope::Characters, 'CharacterResource');

        $this->assertTrue(true); // No exception = pass
    }

    // ---------------------------------------------------------------
    // Error Handling
    // ---------------------------------------------------------------

    public function test_throws_authentication_exception_on401(): void
    {
        $client = $this->createClientWithMock([
            new Response(401, [], json_encode(['error' => 'Invalid token'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid token');

        $client->get('/profile');
    }

    public function test_throws_authentication_exception_on403(): void
    {
        $client = $this->createClientWithMock([
            new Response(403, [], json_encode(['error' => 'Forbidden'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(AuthenticationException::class);

        $client->get('/profile');
    }

    public function test_throws_resource_not_found_on404(): void
    {
        $client = $this->createClientWithMock([
            new Response(404, [], json_encode(['error' => 'Not found'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(ResourceNotFoundException::class);

        $client->get('/character/NonExistent');
    }

    public function test_throws_invalid_request_on400(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['error' => 'Bad request'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(InvalidRequestException::class);

        $client->get('/stash/Invalid');
    }

    public function test_throws_server_exception_on500(): void
    {
        $client = $this->createClientWithMock([
            new Response(500, [], json_encode(['error' => 'Internal error'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(ServerException::class);

        $client->get('/profile');
    }

    public function test_throws_server_exception_on503(): void
    {
        $client = $this->createClientWithMock([
            new Response(503, [], json_encode(['error' => 'Service unavailable'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(ServerException::class);

        $client->get('/profile');
    }

    // ---------------------------------------------------------------
    // Rate Limit Handling
    // ---------------------------------------------------------------

    public function test_rate_limit_exception_on429(): void
    {
        $client = $this->createClientWithMock([
            new Response(429, ['Retry-After' => '30'], json_encode(['error' => 'Rate limited'])),
        ]);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/character');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame('Rate limited', $e->getResponseBody()['error']);
        }
    }

    public function test_rate_limit_records_from_response_headers(): void
    {
        $headers = [
            'X-Rate-Limit-Policy' => 'character-request-limit',
            'X-Rate-Limit-Rules' => 'Account',
            'X-Rate-Limit-Account' => '5:10:60',
            'X-Rate-Limit-Account-State' => '1:10:0',
        ];

        $body = json_encode(['id' => 'test', 'name' => 'TestChar']);
        $client = $this->createClientWithMock([
            new Response(200, $headers, $body),
        ]);
        $client->withToken($this->createValidToken());

        $client->get('/character/TestChar');

        $limiter = $client->getRateLimiter();
        $policy = $limiter->getPolicy('character-request-limit');

        $this->assertNotNull($policy);
    }

    // ---------------------------------------------------------------
    // Resource Accessors
    // ---------------------------------------------------------------

    public function test_resource_accessors_return_correct_types(): void
    {
        $client = new ApiClient(['client_id' => 'test']);

        $this->assertInstanceOf(ProfileResource::class, $client->profile());
        $this->assertInstanceOf(CharacterResource::class, $client->characters());
        $this->assertInstanceOf(LeagueResource::class, $client->leagues());
        $this->assertInstanceOf(ItemFilterResource::class, $client->itemFilters());
        $this->assertInstanceOf(PvpMatchResource::class, $client->pvpMatches());
        $this->assertInstanceOf(GuildResource::class, $client->guild());
        $this->assertInstanceOf(PublicStashTabResource::class, $client->publicStashTabs());
        $this->assertInstanceOf(CurrencyExchangeResource::class, $client->currencyExchange());
        $this->assertInstanceOf(PublicApiClient::class, $client->public());
    }

    public function test_stash_resource_requires_league(): void
    {
        $client = new ApiClient(['client_id' => 'test']);
        $stash = $client->stashes('Mirage');

        $this->assertInstanceOf(StashResource::class, $stash);
    }

    public function test_stash_list_unwraps_stashes_key(): void
    {
        // GGG returns {"stashes": [...]} — list() must unwrap the key, not iterate the response root
        $body = file_get_contents(__DIR__.'/../../fixtures/stash-list.json');

        $client = $this->createClientWithMock([new Response(200, [], $body)]);
        $client->withToken($this->createValidToken());

        $tabs = $client->stashes('Standard')->list();

        $this->assertCount(3, $tabs);
        $this->assertSame('a1b2c3d4e5', $tabs[0]->id);
        $this->assertSame('Currency', $tabs[0]->name);
        $this->assertSame('Maps', $tabs[1]->name);
    }

    public function test_stash_get_unwraps_stash_key(): void
    {
        // GGG returns {"stash": {...}} — get() must unwrap the key so items/children land at the top level
        $body = json_encode([
            'stash' => [
                'id' => 'abc1234567',
                'name' => 'Uniques',
                'type' => 'UniqueStash',
                'children' => [
                    ['id' => 'child001', 'parent' => 'abc1234567', 'type' => 'UniqueStash', 'metadata' => ['items' => 5]],
                    ['id' => 'child002', 'parent' => 'abc1234567', 'type' => 'UniqueStash', 'metadata' => ['items' => 12]],
                ],
                'items' => [],
            ],
        ]);

        $client = $this->createClientWithMock([new Response(200, [], $body)]);
        $client->withToken($this->createValidToken());

        $tab = $client->stashes('Standard')->get('abc1234567');

        $this->assertSame('abc1234567', $tab->id());
        $this->assertSame('Uniques', $tab->name());
        $this->assertSame('UniqueStash', $tab->type());
        $this->assertCount(2, $tab->raw()['children']);
        $this->assertSame('child001', $tab->raw()['children'][0]['id']);
    }

    // ---------------------------------------------------------------
    // User-Agent
    // ---------------------------------------------------------------

    public function test_user_agent_is_built_from_config(): void
    {
        $body = json_encode(['uuid' => 'abc']);
        $mock = new MockHandler([new Response(200, [], $body)]);
        $stack = HandlerStack::create($mock);

        // Add middleware to capture the request
        $capturedRequest = null;
        $stack->push(function (callable $handler) use (&$capturedRequest) {
            return function ($request, array $options) use ($handler, &$capturedRequest) {
                $capturedRequest = $request;

                return $handler($request, $options);
            };
        });

        $client = new ApiClient([
            'client_id' => 'my-app',
            'user_agent' => ['version' => '2.0.0', 'contact' => 'dev@example.com'],
            'rate_limit' => ['strategy' => 'exception'],
        ]);

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler' => $stack,
            'http_errors' => false,
        ]));

        $client->withToken($this->createValidToken());
        $client->get('/profile');

        $ua = $capturedRequest->getHeaderLine('User-Agent');
        $this->assertSame('OAuth my-app/2.0.0 (contact: dev@example.com)', $ua);
    }

    // ---------------------------------------------------------------
    // Token Management
    // ---------------------------------------------------------------

    public function test_with_token_sets_token(): void
    {
        $client = new ApiClient;
        $token = $this->createValidToken();

        $client->withToken($token);

        $this->assertSame($token, $client->getToken());
    }

    public function test_get_token_returns_null_initially(): void
    {
        $client = new ApiClient;

        $this->assertNull($client->getToken());
    }

    public function test_refresh_token_throws_without_token(): void
    {
        $client = new ApiClient;

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No token set');

        $client->refreshToken();
    }

    // ---------------------------------------------------------------
    // Token Refresh Failure Callback
    // ---------------------------------------------------------------

    /**
     * Build a client whose OAuth provider answers a refresh with $status.
     *
     * @param  int  $status  HTTP status the token endpoint returns
     */
    private function createClientWithFailingRefresh(int $status): ApiClient
    {
        $client = new ApiClient(['client_id' => 'test-client']);

        $client->getAuthProvider()->setHttpClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(
                    $status,
                    ['Content-Type' => 'application/json'],
                    json_encode(['error_description' => "Refresh token doesn't exist or has expired"]),
                ),
            ])),
            'http_errors' => false,
        ]));

        $client->withToken($this->createValidToken());

        return $client;
    }

    public function test_refresh_failure_callback_receives_the_original_exception(): void
    {
        $client = $this->createClientWithFailingRefresh(400);
        $received = null;

        $client->onTokenRefreshFailure(function (\Exception $e) use (&$received): void {
            $received = $e;
        });

        try {
            $client->refreshToken();
            $this->fail('refreshToken() should have thrown');
        } catch (AuthenticationException) {
            // expected
        }

        $this->assertInstanceOf(IdentityProviderException::class, $received);
        $this->assertSame(400, $received->getCode(), 'GGG status must survive as the exception code');
    }

    public function test_refresh_failure_callback_sees_the_provider_status_not_the_wrapper(): void
    {
        // The wrapper is constructed with code 0, so a consumer reading the
        // AuthenticationException cannot tell a dead token from a GGG outage.
        $client = $this->createClientWithFailingRefresh(503);
        $received = null;

        $client->onTokenRefreshFailure(function (\Exception $e) use (&$received): void {
            $received = $e;
        });

        try {
            $client->refreshToken();
        } catch (AuthenticationException $wrapper) {
            $this->assertSame(0, $wrapper->getCode());
        }

        $this->assertSame(503, $received->getCode());
    }

    public function test_refresh_failure_callback_is_optional(): void
    {
        $client = $this->createClientWithFailingRefresh(400);

        $this->expectException(AuthenticationException::class);

        $client->refreshToken();
    }

    public function test_failing_refresh_failure_callback_does_not_mask_the_refresh_failure(): void
    {
        $client = $this->createClientWithFailingRefresh(400);

        $client->onTokenRefreshFailure(function (): void {
            throw new \RuntimeException('listener blew up');
        });

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Token refresh failed');

        $client->refreshToken();
    }

    public function test_refresh_failure_callback_does_not_fire_on_success(): void
    {
        $client = new ApiClient(['client_id' => 'test-client']);

        $client->getAuthProvider()->setHttpClient(new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'access_token' => 'new-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                    'scope' => implode(' ', Scope::all()),
                ])),
            ])),
            'http_errors' => false,
        ]));

        $client->withToken($this->createValidToken());

        $fired = false;
        $client->onTokenRefreshFailure(function () use (&$fired): void {
            $fired = true;
        });

        $client->refreshToken();

        $this->assertFalse($fired);
    }

    // ---------------------------------------------------------------
    // Token Refresh (refreshTokenIfNeeded)
    // ---------------------------------------------------------------

    public function test_expired_token_with_no_refresh_token_throws(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token is expired and has no refresh token
        $client->withToken(Token::fromArray([
            'access_token' => 'expired-token',
            'refresh_token' => '',
            'expires_at' => time() - 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $client->get('/profile');
    }

    public function test_expiring_token_with_no_refresh_token_throws(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token expires within the 300s buffer but has no refresh token
        $client->withToken(Token::fromArray([
            'access_token' => 'expiring-token',
            'refresh_token' => '',
            'expires_at' => time() + 60, // Within 300s buffer
            'scope' => implode(' ', Scope::all()),
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expiring');

        $client->get('/profile');
    }

    public function test_valid_token_does_not_trigger_refresh(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token is valid and far from expiry - no refresh needed
        $client->withToken(Token::fromArray([
            'access_token' => 'valid-token',
            'refresh_token' => '',
            'expires_at' => time() + 7200, // 2 hours out
            'scope' => implode(' ', Scope::all()),
        ]));

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
    }

    public function test_request_with_no_token_skips_refresh(): void
    {
        // Public-style request without a token should not trigger refresh logic
        $body = json_encode(['uuid' => 'abc']);
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ]);

        $response = $client->get('/league');

        $this->assertTrue($response->isSuccessful());
    }

    // ---------------------------------------------------------------
    // Timeout Configuration
    // ---------------------------------------------------------------

    public function test_http_client_defaults_to_short_timeouts(): void
    {
        // Guzzle's default timeout (0 = unlimited) sits at/above PHP's 30s
        // max_execution_time, so a hung GGG request fatals instead of raising
        // a catchable Guzzle exception. Timeouts must stay well under 30s.
        $client = new ApiClient(['client_id' => 'test']);

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('httpClient');
        $httpClient = $prop->getValue($client);

        $this->assertSame(12, $httpClient->getConfig('timeout'));
        $this->assertSame(5, $httpClient->getConfig('connect_timeout'));
    }

    public function test_http_client_honors_configured_timeouts(): void
    {
        $client = new ApiClient([
            'client_id' => 'test',
            'timeout' => 20,
            'connect_timeout' => 8,
        ]);

        $reflection = new \ReflectionClass($client);
        $prop = $reflection->getProperty('httpClient');
        $httpClient = $prop->getValue($client);

        $this->assertSame(20, $httpClient->getConfig('timeout'));
        $this->assertSame(8, $httpClient->getConfig('connect_timeout'));
    }
}
