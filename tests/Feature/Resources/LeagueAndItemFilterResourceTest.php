<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\League;
use Braseidon\VaalApi\Enums\Scope;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * GET /league, /league/<id> and the item-filter endpoints wrap their payload
 * in leagues / league / filters / filter (GGG reference.md).
 */
class LeagueAndItemFilterResourceTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * Create an authenticated ApiClient whose Guzzle client answers from a mock
     * queue and records every request it sends.
     *
     * @param  Response[]  $responses  Queued mock responses
     * @param  array  $config  Client config overrides
     */
    private function createClientWithMock(array $responses, array $config = []): ApiClient
    {
        $client = new ApiClient(array_merge([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'safety_margin' => 0.2],
        ], $config));

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('httpClient')->setValue($client, new GuzzleClient([
            'base_uri' => 'https://api.pathofexile.com/',
            'handler' => $stack,
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

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../fixtures/'.$name);
    }

    private function lastPath(): string
    {
        return $this->history[array_key_last($this->history)]['request']->getUri()->getPath();
    }

    public function test_league_list_unwraps_leagues_key(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], $this->fixture('leagues.json'))]);

        $leagues = $client->leagues()->list();

        $this->assertCount(4, $leagues);
        $this->assertContainsOnlyInstancesOf(League::class, $leagues);
        $this->assertSame('Standard', $leagues[0]->id);
        $this->assertSame('/league', $this->lastPath());
    }

    public function test_league_list_fails_loud_without_the_key(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode([['id' => 'Standard']]))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->leagues()->list();
    }

    public function test_league_get_unwraps_league_key(): void
    {
        $league = json_decode($this->fixture('leagues.json'), true)['leagues'][2];
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['league' => $league]))]);

        $result = $client->leagues()->get('Allflame');

        $this->assertSame('Allflame', $result->id);
        $this->assertTrue($result->isCurrent());
        $this->assertSame('/league/Allflame', $this->lastPath());
    }

    public function test_league_get_returns_null_when_ggg_finds_no_league(): void
    {
        // reference.md: "The league object requested or null if it cannot be found"
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['league' => null]))]);

        $this->assertNull($client->leagues()->get('Nope'));
    }

    public function test_league_get_fails_loud_without_the_key(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['id' => 'Allflame']))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->leagues()->get('Allflame');
    }

    public function test_item_filter_list_unwraps_filters_key(): void
    {
        $filter = json_decode($this->fixture('item-filter.json'), true)['filter'];
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['filters' => [$filter]]))]);

        $filters = $client->itemFilters()->list();

        $this->assertCount(1, $filters);
        $this->assertSame('abc123def456', $filters[0]->id);
        $this->assertSame('/item-filter', $this->lastPath());
    }

    public function test_item_filter_get_create_update_unwrap_filter_key(): void
    {
        $body = $this->fixture('item-filter.json');
        $client = $this->createClientWithMock([
            new Response(200, [], $body), new Response(200, [], $body), new Response(200, [], $body),
        ]);

        $this->assertSame('MF Strictness Filter', $client->itemFilters()->get('abc123def456')->name);
        $this->assertSame('MF Strictness Filter', $client->itemFilters()->create(['filter_name' => 'x', 'realm' => 'pc', 'filter' => ''])->name);
        $this->assertSame('MF Strictness Filter', $client->itemFilters()->update('abc123def456', ['description' => 'y'])->name);
    }

    public function test_item_filter_fails_loud_without_the_key(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['id' => 'abc123def456']))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->itemFilters()->get('abc123def456');
    }
}
