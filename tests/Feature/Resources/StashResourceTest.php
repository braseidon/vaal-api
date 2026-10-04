<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Realm;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Psr7\Response;
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
}
