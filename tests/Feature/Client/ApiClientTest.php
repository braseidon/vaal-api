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
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    /**
     * Create an ApiClient with a mocked Guzzle handler.
     *
     * @param  Response[]  $responses  Queued mock responses
     * @param  array  $config  Client config overrides
     * @param  array  $history  Filled with every request/response pair the mock saw
     */
    private function createClientWithMock(array $responses, array $config = [], array &$history = []): ApiClient
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $handler->push(Middleware::history($history));

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
        $history = [];
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ], [], $history);
        $client->withToken($this->createValidToken());

        $response = $client->post('/item-filter', ['name' => 'Test Filter', 'type' => 'Normal']);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('filter-1', $response->data()['id']);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        // The mocked Guzzle client has no base URI, so the path stays relative.
        $this->assertSame('item-filter', $request->getUri()->getPath());
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['name' => 'Test Filter', 'type' => 'Normal'], json_decode((string) $request->getBody(), true));
        $this->assertSame('Bearer test-access-token', $request->getHeaderLine('Authorization'));
    }

    public function test_post_sends_query_parameters_alongside_the_body(): void
    {
        $history = [];
        $client = $this->createClientWithMock([new Response(200, [], '{}')], [], $history);
        $client->withToken($this->createValidToken());

        $client->post('/item-filter', ['name' => 'Test Filter'], ['validate' => 'true']);

        $request = $history[0]['request'];
        $this->assertSame('validate=true', $request->getUri()->getQuery());
        $this->assertSame(['name' => 'Test Filter'], json_decode((string) $request->getBody(), true));
    }

    public function test_requests_carry_the_bearer_token(): void
    {
        $history = [];
        $client = $this->createClientWithMock([new Response(200, [], '{}')], [], $history);
        $client->withToken($this->createValidToken());

        $client->get('/profile');

        $this->assertSame('Bearer test-access-token', $history[0]['request']->getHeaderLine('Authorization'));
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

    /**
     * A response body in GGG's documented error shape
     * (`docs/ai/poe1/apis/_upstream/index.md` § Error Messages). Only code 2
     * ("Invalid query") appears in the docs; the other codes here are stand-ins.
     */
    private function gggError(int $status, int $code, string $message, array $headers = []): Response
    {
        return new Response($status, $headers, json_encode(['error' => ['code' => $code, 'message' => $message]]));
    }

    /**
     * @return array<string, array{int, class-string, string}>
     */
    public static function errorStatuses(): array
    {
        return [
            '401' => [401, AuthenticationException::class, 'Invalid token'],
            '403' => [403, AuthenticationException::class, 'Forbidden'],
            '404' => [404, ResourceNotFoundException::class, 'Resource not found'],
            '400' => [400, InvalidRequestException::class, 'Invalid query'],
            '500' => [500, ServerException::class, 'Internal error'],
            '503' => [503, ServerException::class, 'Service unavailable'],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $exceptionClass
     */
    #[DataProvider('errorStatuses')]
    public function test_an_error_status_throws_its_exception_with_ggg_s_message_as_a_string(int $status, string $exceptionClass, string $message): void
    {
        $client = $this->createClientWithMock([$this->gggError($status, 2, $message)]);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/profile');
            $this->fail("Expected {$exceptionClass}");
        } catch (VaalApiException $e) {
            $this->assertInstanceOf($exceptionClass, $e);
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->getCode());
            $this->assertSame(['error' => ['code' => 2, 'message' => $message]], $e->getResponseBody());
        }
    }

    // ---------------------------------------------------------------
    // Rate Limit Handling
    // ---------------------------------------------------------------

    public function test_rate_limit_exception_on429(): void
    {
        $client = $this->createClientWithMock([
            $this->gggError(429, 3, 'Rate limit exceeded', ['Retry-After' => '30']),
        ]);
        $client->withToken($this->createValidToken());

        try {
            $client->get('/character');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame('Rate limit exceeded', $e->getRateLimitResult()->reason);
            $this->assertSame(['error' => ['code' => 3, 'message' => 'Rate limit exceeded']], $e->getResponseBody());
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
        $this->assertSame('character-request-limit', $policy->name);
        $this->assertSame(['account'], array_keys($policy->rules));

        $window = $policy->rules['account'][0];
        $this->assertSame(5, $window->maxHits);
        $this->assertSame(10, $window->period);
        $this->assertSame(60, $window->penalty);
        $this->assertSame(1, $window->currentHits);
        $this->assertSame(0, $window->activePenalty);
    }

    // ---------------------------------------------------------------
    // Resource Accessors
    // ---------------------------------------------------------------

    public function test_public_hands_the_client_config_to_the_public_client(): void
    {
        $client = new ApiClient([
            'client_id' => 'my-app',
            'user_agent' => ['version' => '2.0.0', 'contact' => 'dev@example.com'],
            'public_url' => 'https://mirror.example.test',
            'timeout' => 33,
        ]);

        $public = $client->public();

        $this->assertInstanceOf(PublicApiClient::class, $public);
        $httpClient = (new \ReflectionProperty($public, 'httpClient'))->getValue($public);
        $this->assertSame(33, $httpClient->getConfig('timeout'));
        $this->assertSame('mirror.example.test', $httpClient->getConfig('base_uri')->getHost());
        $this->assertSame('my-app/2.0.0 (contact: dev@example.com)', $httpClient->getConfig('headers')['User-Agent']);
    }

    public function test_stash_list_unwraps_stashes_key(): void
    {
        // GGG returns {"stashes": [...]} — list() must unwrap the key, not iterate the response root
        $body = file_get_contents(__DIR__.'/../../fixtures/stash-list.json');

        $client = $this->createClientWithMock([new Response(200, [], $body)]);
        $client->withToken($this->createValidToken());

        $tabs = $client->stashes('Standard')->list();

        $this->assertCount(5, $tabs);
        $this->assertSame('a01ab2c0b4', $tabs[0]->id);
        $this->assertSame('$━━━━━━$', $tabs[0]->name);
        $this->assertSame('· Maps', $tabs[3]->name);
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

        $newToken = $client->refreshToken();

        $this->assertFalse($fired);
        $this->assertSame('new-access-token', $newToken->accessToken);
        $this->assertSame($newToken, $client->getToken());
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
