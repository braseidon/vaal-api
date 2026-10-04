<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Dto\Character;
use Braseidon\VaalApi\Dto\CharacterSummary;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CharacterResourceTest extends TestCase
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

    public function test_get_reads_the_wrapped_character(): void
    {
        // GGG returns {"character": {...}}; accessors must see inside the key
        $client = $this->createClientWithMock([new Response(200, [], $this->fixture('character-detail.json'))]);

        $character = $client->characters()->get('MagicFindDotGG');

        $this->assertInstanceOf(Character::class, $character);
        $this->assertSame('MagicFindDotGG', $character->name());
        $this->assertSame(95, $character->level());
        $this->assertSame([918, 1593, 1977, 3452], $character->passiveHashes());
        $this->assertSame('/character/MagicFindDotGG', $this->lastPath());
    }

    public function test_get_raw_keeps_the_character_key_the_app_reads(): void
    {
        // PlannerCharacterImportController reads raw()['character'] and the planner reads `.character`
        $client = $this->createClientWithMock([new Response(200, [], $this->fixture('character-detail.json'))]);

        $raw = $client->characters()->get('MagicFindDotGG')->raw();

        $this->assertSame(['character'], array_keys($raw));
        $this->assertSame('Allflame', $raw['character']['league']);
    }

    public function test_get_fails_loud_when_the_character_key_is_missing(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['name' => 'MagicFindDotGG']))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->characters()->get('MagicFindDotGG');
    }

    public function test_get_puts_the_realm_segment_before_the_name(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], $this->fixture('character-detail.json'))]);

        $client->characters(Realm::Xbox)->get('Some Name');

        $this->assertSame('/character/xbox/Some%20Name', $this->lastPath());
    }

    public function test_default_realm_config_reaches_the_path(): void
    {
        $client = $this->createClientWithMock(
            [new Response(200, [], $this->fixture('character-list.json'))],
            ['default_realm' => 'sony'],
        );

        $client->characters()->list();

        $this->assertSame('/character/sony', $this->lastPath());
    }

    public function test_list_unwraps_characters_key(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], $this->fixture('character-list.json'))]);

        $characters = $client->characters()->list();

        $this->assertCount(2, $characters);
        $this->assertContainsOnlyInstancesOf(CharacterSummary::class, $characters);
        $this->assertSame('VaalSlamDancer', $characters[0]->name);
        $this->assertTrue($characters[1]->current);
        $this->assertSame('/character', $this->lastPath());
    }

    public function test_list_fails_loud_when_the_characters_key_is_missing(): void
    {
        $client = $this->createClientWithMock([new Response(200, [], json_encode(['unexpected' => []]))]);

        $this->expectException(\UnexpectedValueException::class);

        $client->characters()->list();
    }
}
