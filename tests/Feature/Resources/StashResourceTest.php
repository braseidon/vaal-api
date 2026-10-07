<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The request paths StashResource builds: league, stash id, the substash id
 * of a folder, map or unique tab's child, and the realm segment.
 */
class StashResourceTest extends TestCase
{
    use MocksInnermostHandler;

    /**
     * Request paths in the order they reached the network end.
     *
     * @param  array<string, mixed>  $config
     * @param  callable(ApiClient): void  $call
     * @return list<string>
     */
    private function pathsSent(callable $call, array $config = [], int $responses = 1): array
    {
        $client = new ApiClient($config + [
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'auto_retry' => false],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $attempts = [];
        $this->mockInnermostHandler(
            $client,
            array_map(fn () => new Response(200, [], json_encode(['stash' => ['id' => 'x'], 'stashes' => []])), range(1, $responses)),
            $attempts,
        );

        $call($client);

        return array_map(fn (array $attempt) => $attempt['request']->getUri()->getPath(), $attempts);
    }

    public function test_list_path_is_the_league(): void
    {
        $this->assertSame(
            ['/stash/Mirage'],
            $this->pathsSent(fn (ApiClient $c) => $c->stashes('Mirage')->list()),
        );
    }

    public function test_get_path_is_league_then_stash_id(): void
    {
        $this->assertSame(
            ['/stash/Mirage/a01ab2c0b4'],
            $this->pathsSent(fn (ApiClient $c) => $c->stashes('Mirage')->get('a01ab2c0b4')),
        );
    }

    public function test_get_appends_the_substash_id(): void
    {
        $this->assertSame(
            ['/stash/Mirage/a01ab2c0b4/0f1e2d3c4b'],
            $this->pathsSent(fn (ApiClient $c) => $c->stashes('Mirage')->get('a01ab2c0b4', '0f1e2d3c4b')),
        );
    }

    public function test_get_many_appends_the_substash_id_of_a_pair(): void
    {
        $this->assertSame(
            ['/stash/Mirage/a01ab2c0b4', '/stash/Mirage/a01ab2c0b4/0f1e2d3c4b'],
            $this->pathsSent(
                fn (ApiClient $c) => $c->stashes('Mirage')->getMany(['parent' => 'a01ab2c0b4', 'child' => ['a01ab2c0b4', '0f1e2d3c4b']]),
                responses: 2,
            ),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badEntries(): array
    {
        return [
            'an int' => [42],
            'a pair without a string id' => [[123, 'abc']],
            'an empty array' => [[]],
            'null' => [null],
        ];
    }

    #[DataProvider('badEntries')]
    public function test_get_many_refuses_an_entry_that_is_neither_an_id_nor_a_pair(mixed $entry): void
    {
        try {
            $this->pathsSent(function (ApiClient $c) use ($entry): void {
                $c->stashes('Mirage')->getMany(['bad' => $entry]);
            });
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Stash entry 'bad' is neither a stash id nor a [stash id, substash id] pair", $e->getMessage());
        }
    }

    public function test_the_realm_segment_goes_before_the_league(): void
    {
        $this->assertSame(
            ['/stash/xbox/Mirage', '/stash/xbox/Mirage/a01ab2c0b4/0f1e2d3c4b'],
            $this->pathsSent(function (ApiClient $c): void {
                $c->stashes('Mirage', Realm::Xbox)->list();
                $c->stashes('Mirage', Realm::Xbox)->get('a01ab2c0b4', '0f1e2d3c4b');
            }, responses: 2),
        );
    }

    public function test_default_realm_config_reaches_the_path(): void
    {
        $this->assertSame(
            ['/stash/sony/Mirage/a01ab2c0b4'],
            $this->pathsSent(fn (ApiClient $c) => $c->stashes('Mirage')->get('a01ab2c0b4'), ['default_realm' => 'sony']),
        );
    }

    public function test_a_league_name_with_a_space_is_encoded(): void
    {
        $this->assertSame(
            ['/stash/Hardcore%20Mirage'],
            $this->pathsSent(fn (ApiClient $c) => $c->stashes('Hardcore Mirage')->list()),
        );
    }

    /**
     * A client whose network end answers each request with the next body.
     *
     * @param  array<int, array<string, mixed>>  $bodies
     */
    private function clientAnswering(array $bodies): ApiClient
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'auto_retry' => false],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $attempts = [];
        $this->mockInnermostHandler($client, array_map(fn (array $body) => new Response(200, [], json_encode($body)), $bodies), $attempts);

        return $client;
    }

    /**
     * GGG documents Get Stash's `stash` as nullable. A tab that is not there
     * must not come back as an empty tab, which Stash Value would price at 0.
     */
    public function test_get_throws_not_found_for_a_null_stash(): void
    {
        $client = $this->clientAnswering([['stash' => null]]);

        $this->expectException(ResourceNotFoundException::class);

        $client->stashes('Mirage')->get('a01ab2c0b4');
    }

    public function test_get_many_files_a_null_stash_under_failures_and_keeps_the_rest_in_order(): void
    {
        $client = $this->clientAnswering([
            ['stash' => ['id' => 'aaa', 'name' => 'A', 'type' => 'NormalStash', 'items' => []]],
            ['stash' => null],
            ['stash' => ['id' => 'ccc', 'name' => 'C', 'type' => 'NormalStash', 'items' => []]],
        ]);
        $settled = [];

        $batch = $client->stashes('Mirage')->getMany(
            ['a' => 'aaa', 'b' => 'bbb', 'c' => 'ccc'],
            function (int|string $key, mixed $result) use (&$settled): void {
                $settled[$key] = $result::class;
            },
        );

        $this->assertSame(['a', 'c'], array_keys($batch->results));
        $this->assertSame('aaa', $batch->results['a']->id());
        $this->assertSame(['b'], array_keys($batch->failures));
        $this->assertInstanceOf(ResourceNotFoundException::class, $batch->failures['b']);
        $this->assertSame(ResourceNotFoundException::class, $settled['b']);
    }
}
