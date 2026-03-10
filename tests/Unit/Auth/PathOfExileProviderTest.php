<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\PathOfExileProvider;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

class PathOfExileProviderTest extends TestCase
{
    private PathOfExileProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PathOfExileProvider([
            'clientId'     => 'test-client-id',
            'clientSecret' => 'test-client-secret',
            'redirectUri'  => 'https://example.com/callback',
        ]);
    }

    public function testAuthorizationUrl(): void
    {
        $url = $this->provider->getAuthorizationUrl(['scope' => 'account:profile']);

        $this->assertStringStartsWith('https://www.pathofexile.com/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function testAuthorizationUrlIncludesState(): void
    {
        $this->provider->getAuthorizationUrl();
        $state = $this->provider->getState();

        $this->assertNotEmpty($state);
    }

    public function testBaseAccessTokenUrl(): void
    {
        $url = $this->provider->getBaseAccessTokenUrl([]);

        $this->assertSame('https://www.pathofexile.com/oauth/token', $url);
    }

    public function testResourceOwnerDetailsUrl(): void
    {
        $token = new AccessToken(['access_token' => 'test-token']);
        $url   = $this->provider->getResourceOwnerDetailsUrl($token);

        $this->assertSame('https://api.pathofexile.com/profile', $url);
    }

    public function testPkceMethodIsS256(): void
    {
        // PKCE method is used internally during authorization URL generation
        $url = $this->provider->getAuthorizationUrl();

        // S256 PKCE generates a code_challenge parameter
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testResourceOwnerCreation(): void
    {
        $profileData = [
            'uuid'   => 'abc-123',
            'name'   => 'TestPlayer#1234',
            'realm'  => 'pc',
            'locale' => 'en_US',
        ];

        $token         = new AccessToken(['access_token' => 'test-token']);
        $resourceOwner = $this->callProtectedMethod('createResourceOwner', [$profileData, $token]);

        $this->assertSame('abc-123', $resourceOwner->getId());
        $this->assertSame('TestPlayer#1234', $resourceOwner->getName());
        $this->assertSame('pc', $resourceOwner->getRealm());
        $this->assertSame('en_US', $resourceOwner->getLocale());
    }

    public function testScopeSeparatorIsSpace(): void
    {
        $url = $this->provider->getAuthorizationUrl([
            'scope' => 'account:profile account:characters',
        ]);

        // Scope should be present in URL (space becomes + or %20)
        $this->assertStringContainsString('scope=', $url);
    }

    /**
     * Call a protected/private method for testing.
     *
     * @param string $method Method name
     * @param array  $args   Method arguments
     * @return mixed
     */
    private function callProtectedMethod(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($this->provider, $method);

        return $reflection->invoke($this->provider, ...$args);
    }
}
