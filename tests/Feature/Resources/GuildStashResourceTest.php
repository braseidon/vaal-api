<?php

namespace Braseidon\VaalApi\Tests\Feature\Resources;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * GuildStashResource reads inside GGG's wrappers, as StashResource does:
 * {"stashes": [...]} for the list, {"stash": {...}} for one tab.
 */
class GuildStashResourceTest extends TestCase
{
    use MocksInnermostHandler;

    private function clientAnswering(string $body): ApiClient
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
        $this->mockInnermostHandler($client, [new Response(200, [], $body)], $attempts);

        return $client;
    }

    public function test_list_reads_the_stashes_inside_the_wrapper(): void
    {
        $client = $this->clientAnswering(file_get_contents(__DIR__.'/../../fixtures/stash-list.json'));

        $tabs = $client->guild()->stashes('Mirage')->list();

        $this->assertSame(['a01ab2c0b4', '3addc1ac37'], array_slice(array_map(fn ($tab) => $tab->id, $tabs), 0, 2));
    }

    public function test_get_reads_the_tab_inside_the_wrapper(): void
    {
        $client = $this->clientAnswering(file_get_contents(__DIR__.'/../../fixtures/stash-detail.json'));

        $tab = $client->guild()->stashes('Mirage')->get('a01ab2c0b4');

        $this->assertSame('eeeac0167f', $tab->id());
        $this->assertNotEmpty($tab->items());
    }
}
