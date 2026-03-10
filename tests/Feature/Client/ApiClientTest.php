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
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    /**
     * Create an ApiClient with a mocked Guzzle handler.
     *
     * @param Response[] $responses Queued mock responses
     * @param array      $config    Client config overrides
     * @return ApiClient
     */
    private function createClientWithMock(array $responses, array $config = []): ApiClient
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);

        $client = new ApiClient(array_merge([
            'client_id'  => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'safety_margin' => 0.2],
        ], $config));

        // Inject the mocked Guzzle client via reflection
        $reflection = new \ReflectionClass($client);
        $prop       = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler'     => $handler,
            'http_errors' => false,
        ]));

        return $client;
    }

    /**
     * Create a token with all scopes and a far-future expiry.
     *
     * @return Token
     */
    private function createValidToken(): Token
    {
        return Token::fromArray([
            'access_token'  => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at'    => time() + 3600,
            'scope'         => implode(' ', Scope::all()),
        ]);
    }

    // ---------------------------------------------------------------
    // Successful Requests
    // ---------------------------------------------------------------

    public function testGetReturnsApiResponse(): void
    {
        $body   = json_encode(['uuid' => 'abc-123', 'name' => 'Test#1']);
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ]);
        $client->withToken($this->createValidToken());

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('abc-123', $response->data()['uuid']);
    }

    public function testPostSendsJsonBody(): void
    {
        $body   = json_encode(['id' => 'filter-1']);
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

    public function testRequireScopeThrowsWithoutToken(): void
    {
        $client = $this->createClientWithMock([]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('requires authentication');

        $client->requireScope(Scope::Profile, 'ProfileResource');
    }

    public function testRequireScopeThrowsForMissingScope(): void
    {
        $client = $this->createClientWithMock([]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test',
            'scope'        => 'account:profile',
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage("requires scope 'account:characters'");

        $client->requireScope(Scope::Characters, 'CharacterResource');
    }

    public function testRequireScopePassesWithCorrectScope(): void
    {
        $client = $this->createClientWithMock([]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test',
            'scope'        => 'account:profile account:characters',
        ]));

        // Should not throw
        $client->requireScope(Scope::Profile, 'ProfileResource');
        $client->requireScope(Scope::Characters, 'CharacterResource');

        $this->assertTrue(true); // No exception = pass
    }

    // ---------------------------------------------------------------
    // Error Handling
    // ---------------------------------------------------------------

    public function testThrowsAuthenticationExceptionOn401(): void
    {
        $client = $this->createClientWithMock([
            new Response(401, [], json_encode(['error' => 'Invalid token'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid token');

        $client->get('/profile');
    }

    public function testThrowsAuthenticationExceptionOn403(): void
    {
        $client = $this->createClientWithMock([
            new Response(403, [], json_encode(['error' => 'Forbidden'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(AuthenticationException::class);

        $client->get('/profile');
    }

    public function testThrowsResourceNotFoundOn404(): void
    {
        $client = $this->createClientWithMock([
            new Response(404, [], json_encode(['error' => 'Not found'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(ResourceNotFoundException::class);

        $client->get('/character/NonExistent');
    }

    public function testThrowsInvalidRequestOn400(): void
    {
        $client = $this->createClientWithMock([
            new Response(400, [], json_encode(['error' => 'Bad request'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(InvalidRequestException::class);

        $client->get('/stash/Invalid');
    }

    public function testThrowsServerExceptionOn500(): void
    {
        $client = $this->createClientWithMock([
            new Response(500, [], json_encode(['error' => 'Internal error'])),
        ]);
        $client->withToken($this->createValidToken());

        $this->expectException(ServerException::class);

        $client->get('/profile');
    }

    public function testThrowsServerExceptionOn503(): void
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

    public function testRateLimitExceptionOn429(): void
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

    public function testRateLimitRecordsFromResponseHeaders(): void
    {
        $headers = [
            'X-Rate-Limit-Policy'                  => 'character-request-limit',
            'X-Rate-Limit-Rules'                    => 'Account',
            'X-Rate-Limit-Account'                  => '5:10:60',
            'X-Rate-Limit-Account-State'            => '1:10:0',
        ];

        $body   = json_encode(['id' => 'test', 'name' => 'TestChar']);
        $client = $this->createClientWithMock([
            new Response(200, $headers, $body),
        ]);
        $client->withToken($this->createValidToken());

        $client->get('/character/TestChar');

        $limiter = $client->getRateLimiter();
        $policy  = $limiter->getPolicy('character-request-limit');

        $this->assertNotNull($policy);
    }

    // ---------------------------------------------------------------
    // Resource Accessors
    // ---------------------------------------------------------------

    public function testResourceAccessorsReturnCorrectTypes(): void
    {
        $client = new ApiClient(['client_id' => 'test']);

        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\ProfileResource::class, $client->profile());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\CharacterResource::class, $client->characters());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\LeagueResource::class, $client->leagues());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\ItemFilterResource::class, $client->itemFilters());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\PvpMatchResource::class, $client->pvpMatches());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\GuildResource::class, $client->guild());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\PublicStashTabResource::class, $client->publicStashTabs());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\CurrencyExchangeResource::class, $client->currencyExchange());
        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\Public\PublicApiClient::class, $client->public());
    }

    public function testStashResourceRequiresLeague(): void
    {
        $client = new ApiClient(['client_id' => 'test']);
        $stash  = $client->stashes('Mirage');

        $this->assertInstanceOf(\Braseidon\VaalApi\Resources\StashResource::class, $stash);
    }

    // ---------------------------------------------------------------
    // User-Agent
    // ---------------------------------------------------------------

    public function testUserAgentIsBuiltFromConfig(): void
    {
        $body   = json_encode(['uuid' => 'abc']);
        $mock   = new MockHandler([new Response(200, [], $body)]);
        $stack  = HandlerStack::create($mock);

        // Add middleware to capture the request
        $capturedRequest = null;
        $stack->push(function (callable $handler) use (&$capturedRequest) {
            return function ($request, array $options) use ($handler, &$capturedRequest) {
                $capturedRequest = $request;
                return $handler($request, $options);
            };
        });

        $client = new ApiClient([
            'client_id'  => 'my-app',
            'user_agent' => ['version' => '2.0.0', 'contact' => 'dev@example.com'],
            'rate_limit' => ['strategy' => 'exception'],
        ]);

        $reflection = new \ReflectionClass($client);
        $prop       = $reflection->getProperty('httpClient');
        $prop->setValue($client, new GuzzleClient([
            'handler'     => $stack,
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

    public function testWithTokenSetsToken(): void
    {
        $client = new ApiClient();
        $token  = $this->createValidToken();

        $client->withToken($token);

        $this->assertSame($token, $client->getToken());
    }

    public function testGetTokenReturnsNullInitially(): void
    {
        $client = new ApiClient();

        $this->assertNull($client->getToken());
    }

    public function testRefreshTokenThrowsWithoutToken(): void
    {
        $client = new ApiClient();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No token set');

        $client->refreshToken();
    }

    // ---------------------------------------------------------------
    // Token Refresh (refreshTokenIfNeeded)
    // ---------------------------------------------------------------

    public function testExpiredTokenWithNoRefreshTokenThrows(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token is expired and has no refresh token
        $client->withToken(Token::fromArray([
            'access_token'  => 'expired-token',
            'refresh_token' => '',
            'expires_at'    => time() - 3600,
            'scope'         => implode(' ', Scope::all()),
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');

        $client->get('/profile');
    }

    public function testExpiringTokenWithNoRefreshTokenThrows(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token expires within the 300s buffer but has no refresh token
        $client->withToken(Token::fromArray([
            'access_token'  => 'expiring-token',
            'refresh_token' => '',
            'expires_at'    => time() + 60, // Within 300s buffer
            'scope'         => implode(' ', Scope::all()),
        ]));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expiring');

        $client->get('/profile');
    }

    public function testValidTokenDoesNotTriggerRefresh(): void
    {
        $client = $this->createClientWithMock([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        // Token is valid and far from expiry - no refresh needed
        $client->withToken(Token::fromArray([
            'access_token'  => 'valid-token',
            'refresh_token' => '',
            'expires_at'    => time() + 7200, // 2 hours out
            'scope'         => implode(' ', Scope::all()),
        ]));

        $response = $client->get('/profile');

        $this->assertTrue($response->isSuccessful());
    }

    public function testRequestWithNoTokenSkipsRefresh(): void
    {
        // Public-style request without a token should not trigger refresh logic
        $body   = json_encode(['uuid' => 'abc']);
        $client = $this->createClientWithMock([
            new Response(200, [], $body),
        ]);

        $response = $client->get('/league');

        $this->assertTrue($response->isSuccessful());
    }
}
