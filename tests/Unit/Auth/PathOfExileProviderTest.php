<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\PathOfExileProvider;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

class PathOfExileProviderTest extends TestCase
{
    private PathOfExileProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PathOfExileProvider([
            'clientId' => 'test-client-id',
            'clientSecret' => 'test-client-secret',
            'redirectUri' => 'https://example.com/callback',
        ]);
    }

    public function test_authorization_url(): void
    {
        $url = $this->provider->getAuthorizationUrl(['scope' => 'account:profile']);

        $this->assertStringStartsWith('https://www.pathofexile.com/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function test_authorization_url_includes_state(): void
    {
        $this->provider->getAuthorizationUrl();
        $state = $this->provider->getState();

        $this->assertNotEmpty($state);
    }

    public function test_base_access_token_url(): void
    {
        $url = $this->provider->getBaseAccessTokenUrl([]);

        $this->assertSame('https://www.pathofexile.com/oauth/token', $url);
    }

    public function test_resource_owner_details_url(): void
    {
        $token = new AccessToken(['access_token' => 'test-token']);
        $url = $this->provider->getResourceOwnerDetailsUrl($token);

        $this->assertSame('https://api.pathofexile.com/profile', $url);
    }

    public function test_pkce_method_is_s256(): void
    {
        // PKCE method is used internally during authorization URL generation
        $url = $this->provider->getAuthorizationUrl();

        // S256 PKCE generates a code_challenge parameter
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_resource_owner_creation(): void
    {
        $profileData = [
            'uuid' => 'abc-123',
            'name' => 'TestPlayer#1234',
            'realm' => 'pc',
            'locale' => 'en_US',
        ];

        $token = new AccessToken(['access_token' => 'test-token']);
        $resourceOwner = $this->callProtectedMethod('createResourceOwner', [$profileData, $token]);

        $this->assertSame('abc-123', $resourceOwner->getId());
        $this->assertSame('TestPlayer#1234', $resourceOwner->getName());
        $this->assertSame('pc', $resourceOwner->getRealm());
        $this->assertSame('en_US', $resourceOwner->getLocale());
    }

    public function test_scope_separator_is_space(): void
    {
        // A scope list is joined by the provider's separator.
        $url = $this->provider->getAuthorizationUrl([
            'scope' => ['account:profile', 'account:characters'],
        ]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('account:profile account:characters', $query['scope']);
    }

    /**
     * Point the provider's HTTP client at queued responses.
     *
     * @param  Response[]  $responses
     * @param  array  $history  Filled with every request/response pair
     */
    private function mockHttp(array $responses, array &$history = []): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $this->provider->setHttpClient(new GuzzleClient(['handler' => $stack, 'http_errors' => false]));
    }

    public function test_revoke_posts_the_token_and_client_credentials_as_a_form(): void
    {
        $history = [];
        $this->mockHttp([new Response(200, ['Content-Type' => 'application/json'], '')], $history);

        $this->provider->revokeToken('the-refresh-token', 'refresh_token');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://www.pathofexile.com/oauth/token/revoke', (string) $request->getUri());
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('OAuth test-client-id/', $request->getHeaderLine('User-Agent'));

        parse_str((string) $request->getBody(), $form);
        $this->assertSame([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'token' => 'the-refresh-token',
            'token_type_hint' => 'refresh_token',
        ], $form);
    }

    public function test_revoke_leaves_out_an_absent_type_hint(): void
    {
        $history = [];
        $this->mockHttp([new Response(200)], $history);

        $this->provider->revokeToken('the-access-token');

        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertArrayNotHasKey('token_type_hint', $form);
    }

    public function test_a_refused_revoke_throws_with_ggg_s_status(): void
    {
        $this->mockHttp([new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}')]);

        try {
            $this->provider->revokeToken('the-access-token');
            $this->fail('Expected IdentityProviderException');
        } catch (IdentityProviderException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('invalid_client', $e->getMessage());
        }
    }

    /**
     * The league provider's default Guzzle client throws on a 4xx, unlike the
     * mocks above, so the refusal arrives as a BadResponseException.
     */
    public function test_a_refused_revoke_throws_with_ggg_s_status_when_guzzle_throws_on_errors(): void
    {
        $this->provider->setHttpClient(new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}'),
        ]))]));

        try {
            $this->provider->revokeToken('the-access-token');
            $this->fail('Expected IdentityProviderException');
        } catch (IdentityProviderException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('invalid_client', $e->getMessage());
        }
    }

    public function test_an_error_that_is_not_a_string_still_becomes_a_string_message(): void
    {
        $this->mockHttp([new Response(400, ['Content-Type' => 'application/json'], '{"error":{"code":3}}')]);

        try {
            $this->provider->getAccessToken('refresh_token', ['refresh_token' => 'x']);
            $this->fail('Expected IdentityProviderException');
        } catch (IdentityProviderException $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('{"code":3}', $e->getMessage());
        }
    }

    /**
     * Call a protected/private method for testing.
     *
     * @param  string  $method  Method name
     * @param  array  $args  Method arguments
     */
    private function callProtectedMethod(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($this->provider, $method);

        return $reflection->invoke($this->provider, ...$args);
    }
}
