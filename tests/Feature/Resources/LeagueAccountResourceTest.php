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
 * The request path LeagueAccountResource builds: the league name, and the
 * realm segment before it.
 */
class LeagueAccountResourceTest extends TestCase
{
    use MocksInnermostHandler;

    /**
     * Request paths in the order they reached the network end.
     *
     * @param  array<string, mixed>  $config
     * @param  callable(ApiClient): void  $call
     * @return list<string>
     */
    private function pathsSent(callable $call, array $config = []): array
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
            [new Response(200, [], json_encode(['league_account' => ['atlas_passives' => []]]))],
            $attempts,
        );

        $call($client);

        return array_map(fn (array $attempt) => $attempt['request']->getUri()->getPath(), $attempts);
    }

    public function test_get_path_is_the_league(): void
    {
        $this->assertSame(
            ['/league-account/Mirage'],
            $this->pathsSent(fn (ApiClient $c) => $c->leagueAccount('Mirage')->get()),
        );
    }

    public function test_the_realm_segment_goes_before_the_league(): void
    {
        $this->assertSame(
            ['/league-account/xbox/Mirage'],
            $this->pathsSent(fn (ApiClient $c) => $c->leagueAccount('Mirage', Realm::Xbox)->get()),
        );
    }

    public function test_a_league_name_with_a_space_is_encoded(): void
    {
        $this->assertSame(
            ['/league-account/Hardcore%20Mirage'],
            $this->pathsSent(fn (ApiClient $c) => $c->leagueAccount('Hardcore Mirage')->get()),
        );
    }
}
