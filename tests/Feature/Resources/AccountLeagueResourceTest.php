<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\League;
use Braseidon\VaalApi\Enums\Scope;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AccountLeagueResourceTest extends TestCase
{
    /**
     * Create an authenticated ApiClient whose Guzzle client answers from a mock queue.
     *
     * @param  Response[]  $responses  Queued mock responses
     */
    private function createClientWithMock(array $responses): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'safety_margin' => 0.2],
        ]);

        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('httpClient')->setValue($client, new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]));

        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        return $client;
    }

    public function test_list_unwraps_leagues_key(): void
    {
        // GGG returns {"leagues": [...]} — list() must unwrap the key, not iterate the response root
        $body = file_get_contents(__DIR__.'/../../fixtures/account-leagues.json');
        $client = $this->createClientWithMock([new Response(200, [], $body)]);

        $leagues = $client->accountLeagues()->list();

        $this->assertCount(17, $leagues);
        $this->assertContainsOnlyInstancesOf(League::class, $leagues);
        $this->assertSame('Standard', $leagues[0]->id);
        $this->assertTrue($this->anyCurrent($leagues));
    }

    public function test_list_fails_loud_when_the_leagues_key_is_missing(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['unexpected' => []]))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->accountLeagues()->list();
    }

    /**
     * @param  League[]  $leagues
     */
    private function anyCurrent(array $leagues): bool
    {
        foreach ($leagues as $league) {
            if ($league->isCurrent()) {
                return true;
            }
        }

        return false;
    }
}
