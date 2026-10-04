<?php

namespace Braseidon\VaalApi\Tests\Feature;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use Braseidon\VaalApi\VaalApi;
use PHPUnit\Framework\TestCase;

class VaalApiTest extends TestCase
{
    public function test_for_returns_authenticated_client(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'expires_at' => time() + 3600,
            'scope' => 'account:profile',
        ]);

        $client = VaalApi::for($token);

        $this->assertInstanceOf(ApiClient::class, $client);
        $this->assertSame($token, $client->getToken());
    }

    public function test_for_passes_config(): void
    {
        $token = Token::fromArray(['access_token' => 'test']);
        $client = VaalApi::for($token, [
            'client_id' => 'my-app',
            'redirect_uri' => 'https://example.test/callback',
            'timeout' => 33,
        ]);

        // The OAuth provider is built from the client's config.
        $authUrl = $client->getAuthProvider()->getAuthorizationUrl();
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $query);
        $this->assertSame('my-app', $query['client_id']);
        $this->assertSame('https://example.test/callback', $query['redirect_uri']);

        // So is the HTTP client.
        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $this->assertSame(33, $httpClient->getConfig('timeout'));
    }

    public function test_public_returns_public_client(): void
    {
        $client = VaalApi::public();

        $this->assertInstanceOf(PublicApiClient::class, $client);
    }

    public function test_public_passes_config(): void
    {
        $client = VaalApi::public([
            'client_id' => 'my-app',
            'public_url' => 'https://mirror.example.test',
            'timeout' => 60,
        ]);

        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $this->assertSame(60, $httpClient->getConfig('timeout'));
        $this->assertSame('mirror.example.test', $httpClient->getConfig('base_uri')->getHost());
        $this->assertStringStartsWith('my-app/', $httpClient->getConfig('headers')['User-Agent']);
    }
}
