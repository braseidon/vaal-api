<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\AuthenticationException;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The automatic token refresh a request triggers.
 *
 * GGG invalidates the old refresh token the moment it is used, and the app
 * saves the new token through onTokenRefresh. A refresh whose callback does not
 * fire, or whose new token is not the one the next request sends, logs the
 * user out of every OAuth tool.
 */
class TokenRefreshTest extends TestCase
{
    use MocksInnermostHandler;

    private const NEW_SCOPE = 'account:profile account:characters account:stashes';

    /**
     * @param  array<int, array{request: RequestInterface, response: mixed}>  $tokenRequests  Requests the token endpoint received
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $apiRequests  Requests the API received
     * @param  array<int, mixed>  $apiResponses
     * @param  array<int, mixed>  $tokenResponses
     */
    private function createClient(array $tokenResponses, array $apiResponses, array &$tokenRequests, array &$apiRequests): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'rate_limit' => ['strategy' => 'exception'],
        ]);

        $this->mockInnermostHandler($client, $apiResponses, $apiRequests);

        $tokenStack = HandlerStack::create(new MockHandler($tokenResponses));
        $tokenStack->push(Middleware::history($tokenRequests));
        $client->getAuthProvider()->setHttpClient(new GuzzleClient([
            'handler' => $tokenStack,
            'http_errors' => false,
        ]));

        return $client;
    }

    private function tokenResponse(string $access = 'new-access-token', string $refresh = 'new-refresh-token'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 36000,
            'token_type' => 'bearer',
            'scope' => self::NEW_SCOPE,
            'username' => 'Exile#1234',
            'sub' => 'uuid-new',
        ]));
    }

    private function ok(): Response
    {
        return new Response(200, [], json_encode(['uuid' => 'abc']));
    }

    private function tokenExpiringIn(int $seconds): Token
    {
        return Token::fromArray([
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => time() + $seconds,
            'scope' => 'account:profile',
        ]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function tokensThatNeedRefresh(): array
    {
        return [
            'inside the 300 s refresh buffer' => [60],
            'already expired' => [-10],
        ];
    }

    #[DataProvider('tokensThatNeedRefresh')]
    public function test_the_refresh_callback_receives_the_new_token(int $expiresIn): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [$this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn($expiresIn));

        $received = [];
        $client->onTokenRefresh(function (Token $token) use (&$received): void {
            $received[] = $token;
        });

        $before = time();
        $client->get('/profile');
        $after = time();

        $this->assertCount(1, $received, 'the callback must fire exactly once');
        $this->assertSame('new-access-token', $received[0]->accessToken);
        $this->assertSame('new-refresh-token', $received[0]->refreshToken);
        $this->assertSame(self::NEW_SCOPE, $received[0]->scope);
        $this->assertSame('Exile#1234', $received[0]->username);
        $this->assertSame('uuid-new', $received[0]->sub);
        $this->assertGreaterThanOrEqual($before + 36000, $received[0]->expiresAt);
        $this->assertLessThanOrEqual($after + 36000, $received[0]->expiresAt);
    }

    #[DataProvider('tokensThatNeedRefresh')]
    public function test_the_request_after_a_refresh_carries_the_new_access_token(int $expiresIn): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [$this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn($expiresIn));

        // No onTokenRefresh callback registered: the swap must not depend on one.
        $client->get('/profile');

        $this->assertCount(1, $apiRequests);
        $this->assertSame('Bearer new-access-token', $apiRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('new-access-token', $client->getToken()->accessToken);
    }

    public function test_the_refresh_posts_the_old_refresh_token_to_the_token_endpoint(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [$this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));

        $client->get('/profile');

        $this->assertCount(1, $tokenRequests);
        $request = $tokenRequests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://www.pathofexile.com/oauth/token', (string) $request->getUri());

        parse_str((string) $request->getBody(), $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('old-refresh-token', $form['refresh_token']);
        $this->assertSame('test-client', $form['client_id']);
    }

    public function test_the_refreshed_token_keeps_the_scope_the_provider_granted(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [$this->ok()], $tokenRequests, $apiRequests);
        // The old token only had account:profile.
        $client->withToken($this->tokenExpiringIn(60));

        $client->get('/profile');

        $this->assertSame(self::NEW_SCOPE, $client->getToken()->scope);
        // A scoped resource now passes its own check; with a dropped scope it throws.
        $client->requireScope(Scope::Characters, 'CharacterResource');
        $client->requireScope(Scope::Stashes, 'StashResource');
        $this->addToAssertionCount(2);
    }

    public function test_a_refreshed_token_is_not_refreshed_again_by_the_next_request(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        // One token response queued: a second refresh would have nothing to answer it.
        $client = $this->createClient([$this->tokenResponse()], [$this->ok(), $this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));

        $refreshes = 0;
        $client->onTokenRefresh(function () use (&$refreshes): void {
            $refreshes++;
        });

        $client->get('/profile');
        $client->get('/profile');

        $this->assertSame(1, $refreshes);
        $this->assertCount(1, $tokenRequests);
        $this->assertCount(2, $apiRequests);
        $this->assertSame('Bearer new-access-token', $apiRequests[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_the_new_token_is_handed_over_before_the_request_is_sent(): void
    {
        $events = [];
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [
            function () use (&$events): Response {
                $events[] = 'api request sent';

                return $this->ok();
            },
        ], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));
        $client->onTokenRefresh(function () use (&$events): void {
            $events[] = 'new token saved';
        });

        $client->get('/profile');

        $this->assertSame(['new token saved', 'api request sent'], $events);
    }

    public function test_a_rejected_refresh_fires_the_failure_callback_and_sends_no_request(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
                'error_description' => "Refresh token doesn't exist or has expired",
            ])),
        ], [$this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));

        $saved = 0;
        $failure = null;
        $client->onTokenRefresh(function () use (&$saved): void {
            $saved++;
        });
        $client->onTokenRefreshFailure(function (\Exception $e) use (&$failure): void {
            $failure = $e;
        });

        try {
            $client->get('/profile');
            $this->fail('A rejected refresh must stop the request');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('Token refresh failed', $e->getMessage());
        }

        $this->assertInstanceOf(IdentityProviderException::class, $failure);
        $this->assertSame(400, $failure->getCode());
        $this->assertSame(0, $saved, 'no new token exists to save');
        $this->assertSame([], $apiRequests, 'the API must not be called with a token known to be dead');
        $this->assertSame('old-access-token', $client->getToken()->accessToken);
    }

    public function test_get_many_refreshes_an_expiring_token_and_sends_the_new_one(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([$this->tokenResponse()], [$this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));

        $saved = [];
        $client->onTokenRefresh(function (Token $token) use (&$saved): void {
            $saved[] = $token->accessToken;
        });

        $result = $client->getMany(['tab' => '/profile']);

        $this->assertSame(['new-access-token'], $saved);
        $this->assertCount(1, $apiRequests);
        $this->assertSame('Bearer new-access-token', $apiRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame([], $result->failures);
    }

    public function test_get_many_fails_every_key_when_the_refresh_is_rejected(): void
    {
        $tokenRequests = [];
        $apiRequests = [];
        $client = $this->createClient([
            new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant'])),
        ], [$this->ok(), $this->ok()], $tokenRequests, $apiRequests);
        $client->withToken($this->tokenExpiringIn(60));

        $failed = 0;
        $client->onTokenRefreshFailure(function () use (&$failed): void {
            $failed++;
        });

        $result = $client->getMany(['a' => '/profile', 'b' => '/profile']);

        $this->assertSame(1, $failed);
        $this->assertSame([], $apiRequests);
        $this->assertSame(['a', 'b'], array_keys($result->failures));
        $this->assertInstanceOf(AuthenticationException::class, $result->failures['a']);
    }
}
