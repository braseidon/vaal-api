<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Exceptions\ConnectionException;
use Braseidon\VaalApi\Exceptions\InvalidRequestException;
use Braseidon\VaalApi\Exceptions\ResourceNotFoundException;
use Braseidon\VaalApi\Exceptions\ServerException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\Resources\Public\PublicApiClient;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use Braseidon\VaalApi\VaalApi;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * PublicApiClient: the no-OAuth client the app uses for public character
 * lists, equipped items and passive trees (GggApiService::publicClient()).
 *
 * Clients are built through the constructor and only the network end is
 * mocked, so the base URL, User-Agent and Accept header are the real ones.
 */
class PublicApiClientTest extends TestCase
{
    use MocksInnermostHandler;

    private const CONFIG = [
        'client_id' => 'my-app',
        'user_agent' => ['version' => '2.0.0', 'contact' => 'dev@example.com'],
    ];

    /**
     * @param  array<int, mixed>  $responses
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $attempts
     */
    private function createClient(array $responses, array &$attempts, array $config = []): PublicApiClient
    {
        $client = new PublicApiClient(array_merge(self::CONFIG, $config));
        $this->mockInnermostHandler($client, $responses, $attempts);

        return $client;
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, [], json_encode($body));
    }

    // ---------------------------------------------------------------
    // Request shape
    // ---------------------------------------------------------------

    public function test_requests_go_to_the_public_site_with_json_accept_and_no_authorization(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, ['ok' => true])], $attempts);

        $client->get('/character-window/get-characters', ['accountName' => 'Exile#1234']);

        $request = $attempts[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('www.pathofexile.com', $request->getUri()->getHost());
        $this->assertSame('/character-window/get-characters', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function test_the_public_url_config_replaces_the_host(): void
    {
        $attempts = [];
        $client = $this->createClient(
            [$this->json(200, [])],
            $attempts,
            ['public_url' => 'https://mirror.example.test'],
        );

        $client->get('/api/leagues');

        $this->assertSame('mirror.example.test', $attempts[0]['request']->getUri()->getHost());
        $this->assertSame('/api/leagues', $attempts[0]['request']->getUri()->getPath());
    }

    public function test_the_user_agent_has_no_oauth_prefix(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, [])], $attempts);

        $client->get('/api/leagues');

        $this->assertSame(
            'my-app/2.0.0 (contact: dev@example.com)',
            $attempts[0]['request']->getHeaderLine('User-Agent'),
        );
    }

    public function test_the_user_agent_omits_the_contact_when_none_is_configured(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, [])], $attempts, ['user_agent' => ['version' => '3.1.0']]);

        $client->get('/api/leagues');

        $this->assertSame('my-app/3.1.0', $attempts[0]['request']->getHeaderLine('User-Agent'));
    }

    public function test_the_config_the_static_entry_point_receives_reaches_the_client(): void
    {
        $client = VaalApi::public(self::CONFIG + ['public_url' => 'https://mirror.example.test', 'timeout' => 33]);
        $attempts = [];
        $this->mockInnermostHandler($client, [$this->json(200, [])], $attempts);

        $client->get('/api/leagues');

        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $this->assertSame(33, $httpClient->getConfig('timeout'));
        $this->assertSame('mirror.example.test', $attempts[0]['request']->getUri()->getHost());
        $this->assertSame('my-app/2.0.0 (contact: dev@example.com)', $attempts[0]['request']->getHeaderLine('User-Agent'));
    }

    public function test_the_http_client_defaults_to_short_timeouts(): void
    {
        $client = new PublicApiClient;
        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);

        $this->assertSame(12, $httpClient->getConfig('timeout'));
        $this->assertSame(5, $httpClient->getConfig('connect_timeout'));
    }

    public function test_post_sends_the_data_as_a_json_body(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, ['id' => 'abc'])], $attempts);

        $response = $client->post('/api/trade/search/Standard', ['query' => ['status' => ['option' => 'online']]]);

        $request = $attempts[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/trade/search/Standard', $request->getUri()->getPath());
        $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['query' => ['status' => ['option' => 'online']]],
            json_decode((string) $request->getBody(), true),
        );
        $this->assertSame('abc', $response->data()['id']);
    }

    // ---------------------------------------------------------------
    // Character endpoints the app calls
    // ---------------------------------------------------------------

    public function test_character_list_asks_for_the_account_and_returns_the_decoded_body(): void
    {
        $attempts = [];
        $characters = [['name' => 'Alpha', 'league' => 'Standard', 'level' => 90]];
        $client = $this->createClient([$this->json(200, $characters)], $attempts);

        $result = $client->characters('Exile#1234')->list();

        $request = $attempts[0]['request'];
        $this->assertSame('/character-window/get-characters', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['accountName' => 'Exile#1234'], $query);
        $this->assertSame($characters, $result);
    }

    public function test_character_list_passes_the_realm_only_when_given(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, []), $this->json(200, [])], $attempts);

        $client->characters('Exile#1234')->list('sony');

        parse_str($attempts[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['accountName' => 'Exile#1234', 'realm' => 'sony'], $query);
    }

    public function test_character_items_ask_for_the_account_and_the_character(): void
    {
        $attempts = [];
        $items = ['items' => [['name' => 'Tabula Rasa']], 'character' => ['name' => 'Alpha']];
        $client = $this->createClient([$this->json(200, $items)], $attempts);

        $result = $client->characters('Exile#1234')->items('Alpha Beta');

        $request = $attempts[0]['request'];
        $this->assertSame('/character-window/get-items', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['accountName' => 'Exile#1234', 'character' => 'Alpha Beta'], $query);
        $this->assertSame($items, $result);
    }

    public function test_character_passives_ask_for_the_account_and_the_character(): void
    {
        $attempts = [];
        $passives = ['hashes' => [1, 2, 3], 'hashes_ex' => []];
        $client = $this->createClient([$this->json(200, $passives)], $attempts);

        $result = $client->characters('Exile#1234')->passives('Alpha');

        $request = $attempts[0]['request'];
        $this->assertSame('/character-window/get-passive-skills', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['accountName' => 'Exile#1234', 'character' => 'Alpha'], $query);
        $this->assertSame($passives, $result);
    }

    public function test_items_and_passives_pass_the_realm_only_when_given(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(200, []), $this->json(200, [])], $attempts);

        $client->characters('Exile#1234')->items('Alpha', 'xbox');
        $client->characters('Exile#1234')->passives('Alpha', 'xbox');

        foreach ($attempts as $attempt) {
            parse_str($attempt['request']->getUri()->getQuery(), $query);
            $this->assertSame('xbox', $query['realm']);
        }
    }

    // ---------------------------------------------------------------
    // Error mapping (get and post share it)
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{int, class-string<VaalApiException>}>
     */
    public static function statusesAndTheirExceptions(): array
    {
        return [
            '404 is a missing resource' => [404, ResourceNotFoundException::class],
            '403 (a private profile) is an invalid request' => [403, InvalidRequestException::class],
            '400' => [400, InvalidRequestException::class],
            '429 is an invalid request, the public client has no rate limit handling' => [429, InvalidRequestException::class],
            '500' => [500, ServerException::class],
            '503' => [503, ServerException::class],
            '304 is neither success nor error: the base exception' => [304, VaalApiException::class],
        ];
    }

    /**
     * @param  class-string<VaalApiException>  $exception
     */
    #[DataProvider('statusesAndTheirExceptions')]
    public function test_get_maps_a_status_to_its_exception_and_keeps_the_status_as_the_code(int $status, string $exception): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json($status, ['error' => 'nope'])], $attempts);

        try {
            $client->get('/character-window/get-characters', ['accountName' => 'Exile#1234']);
            $this->fail('Expected '.$exception);
        } catch (VaalApiException $e) {
            // Exact class: ResourceNotFoundException and friends all extend the base.
            $this->assertSame($exception, $e::class);
            $this->assertSame($status, $e->getCode());
            $this->assertSame(['error' => 'nope'], $e->getResponseBody());
        }

        $this->assertCount(1, $attempts, 'the public client does not retry');
    }

    /**
     * @param  class-string<VaalApiException>  $exception
     */
    #[DataProvider('statusesAndTheirExceptions')]
    public function test_post_maps_a_status_to_its_exception_and_keeps_the_status_as_the_code(int $status, string $exception): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json($status, ['error' => 'nope'])], $attempts);

        try {
            $client->post('/api/trade/search/Standard', ['query' => []]);
            $this->fail('Expected '.$exception);
        } catch (VaalApiException $e) {
            $this->assertSame($exception, $e::class);
            $this->assertSame($status, $e->getCode());
        }
    }

    public function test_the_message_comes_from_ggg_error_object(): void
    {
        $attempts = [];
        $client = $this->createClient([
            $this->json(404, ['error' => ['code' => 1, 'message' => 'Resource not found']]),
        ], $attempts);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found');

        $client->get('/character-window/get-items');
    }

    public function test_the_message_comes_from_a_plain_error_string(): void
    {
        $attempts = [];
        $client = $this->createClient([$this->json(400, ['error' => 'Bad account name'])], $attempts);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Bad account name');

        $client->get('/character-window/get-items');
    }

    public function test_the_message_falls_back_to_the_status_when_the_body_has_no_error(): void
    {
        $attempts = [];
        $client = $this->createClient([new Response(502, [], 'Bad Gateway')], $attempts);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('HTTP 502');

        $client->get('/character-window/get-items');
    }

    public function test_a_transfer_failure_becomes_a_connection_exception_carrying_the_cause(): void
    {
        $attempts = [];
        $cause = new ConnectException('cURL error 28: timed out', new Request('GET', 'character-window/get-items'));
        $client = $this->createClient([$cause], $attempts);

        try {
            $client->get('/character-window/get-items');
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('did not respond', $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function test_post_turns_a_transfer_failure_into_a_connection_exception(): void
    {
        $attempts = [];
        $cause = new ConnectException('cURL error 6: could not resolve host', new Request('POST', 'api/trade/search'));
        $client = $this->createClient([$cause], $attempts);

        $this->expectException(ConnectionException::class);

        $client->post('/api/trade/search/Standard', []);
    }
}
