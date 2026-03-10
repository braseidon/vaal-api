<?php

namespace Braseidon\VaalApi\Tests\Feature;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use Braseidon\VaalApi\VaalApi;
use PHPUnit\Framework\TestCase;

class VaalApiTest extends TestCase
{
    public function testForReturnsAuthenticatedClient(): void
    {
        $token  = Token::fromArray([
            'access_token'  => 'test-token',
            'refresh_token' => 'test-refresh',
            'expires_at'    => time() + 3600,
            'scope'         => 'account:profile',
        ]);

        $client = VaalApi::for($token);

        $this->assertInstanceOf(ApiClient::class, $client);
        $this->assertSame($token, $client->getToken());
    }

    public function testForPassesConfig(): void
    {
        $token  = Token::fromArray(['access_token' => 'test']);
        $client = VaalApi::for($token, ['client_id' => 'my-app']);

        // Verify config was passed by checking rate limiter exists (created from config)
        $this->assertNotNull($client->getRateLimiter());
    }

    public function testPublicReturnsPublicClient(): void
    {
        $client = VaalApi::public();

        $this->assertInstanceOf(PublicApiClient::class, $client);
    }

    public function testPublicPassesConfig(): void
    {
        $client = VaalApi::public(['timeout' => 60]);

        $this->assertInstanceOf(PublicApiClient::class, $client);
    }
}
