<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * GGG applies a different policy to a list endpoint and to its detail
 * endpoint. The pre-flight check finds the policy by the request's path, so a
 * list path and a detail path must never share a key, with or without the
 * optional realm segment.
 */
class PolicyForPathTest extends TestCase
{
    private const STASH_LIST_FULL = [
        'X-Rate-Limit-Policy' => 'stash-list-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '10:15:60,30:60:300',
        'X-Rate-Limit-Account-State' => '10:15:0,10:60:0',
    ];

    private const STASH_READ = [
        'X-Rate-Limit-Policy' => 'stash-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '15:10:60,30:300:300',
        'X-Rate-Limit-Account-State' => '1:10:0,1:300:0',
    ];

    private const CHARACTER_LIST_FULL = [
        'X-Rate-Limit-Policy' => 'character-list-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '2:10:60,5:300:300',
        'X-Rate-Limit-Account-State' => '2:10:0,2:300:0',
    ];

    private const CHARACTER_READ = [
        'X-Rate-Limit-Policy' => 'character-request-limit',
        'X-Rate-Limit-Rules' => 'Account',
        'X-Rate-Limit-Account' => '5:10:60,30:300:300',
        'X-Rate-Limit-Account-State' => '1:10:0,1:300:0',
    ];

    /**
     * @param  array  $responses  Queued mock responses
     * @param  array  $history  Collects every request the client sent
     */
    private function client(array $responses, array &$history): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'safety_margin' => 0.0, 'auto_retry' => false],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history), 'history');
        (new \ReflectionProperty($client, 'httpClient'))->setValue($client, new GuzzleClient([
            'handler' => $stack,
            'http_errors' => false,
        ]));

        return $client;
    }

    public function test_a_full_stash_list_window_does_not_hold_back_a_tab_read_without_a_realm(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, self::STASH_LIST_FULL, json_encode(['stashes' => []])),
            new Response(200, self::STASH_READ, json_encode(['stash' => ['id' => 'abc123']])),
            new Response(200, self::STASH_READ, json_encode(['stash' => ['id' => 'def456']])),
        ], $history);

        $client->stashes('Mirage')->list();
        $client->stashes('Mirage')->get('abc123');
        $client->stashes('Mirage')->get('abc123', 'def456');

        $this->assertCount(3, $history);
        $limiter = $client->getRateLimiter();
        $this->assertSame('stash-list-request-limit', $limiter->policyForPath('/stash/{league}'));
        $this->assertSame('stash-request-limit', $limiter->policyForPath('/stash/{league}/{id}'));
    }

    public function test_a_full_stash_list_window_does_not_hold_back_a_tab_read_with_a_realm(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, self::STASH_LIST_FULL, json_encode(['stashes' => []])),
            new Response(200, self::STASH_READ, json_encode(['stash' => ['id' => 'abc123']])),
        ], $history);

        $client->stashes('Mirage', Realm::Pc)->list();
        $client->stashes('Mirage', Realm::Pc)->get('abc123');

        $this->assertCount(2, $history);
    }

    public function test_a_full_character_list_window_does_not_hold_back_a_character_read_without_a_realm(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, self::CHARACTER_LIST_FULL, json_encode(['characters' => []])),
            new Response(200, self::CHARACTER_READ, json_encode(['character' => ['name' => 'SomeName']])),
        ], $history);

        $client->characters()->list();
        $client->characters()->get('SomeName');

        $this->assertCount(2, $history);
        $limiter = $client->getRateLimiter();
        $this->assertSame('character-list-request-limit', $limiter->policyForPath('/character'));
        $this->assertSame('character-request-limit', $limiter->policyForPath('/character/{name}'));
    }
}
