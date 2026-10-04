<?php

namespace Braseidon\VaalApi\Tests\Feature\Client;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Client\ApiClient;
use Braseidon\VaalApi\Enums\Scope;
use Braseidon\VaalApi\Exceptions\ConnectionException;
use Braseidon\VaalApi\Exceptions\VaalApiException;
use Braseidon\VaalApi\Tests\Support\MocksInnermostHandler;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A request that never gets a response surfaces as the package's
 * ConnectionException: the app catches VaalApiException, so a raw Guzzle
 * exception would escape as a 500.
 */
class ConnectionFailureTest extends TestCase
{
    use MocksInnermostHandler;

    /**
     * @return array<string, array{bool}>
     */
    public static function retrySettings(): array
    {
        return [
            'retry middleware installed (CLI, jobs)' => [true],
            'auto_retry off (web requests)' => [false],
        ];
    }

    #[DataProvider('retrySettings')]
    public function test_a_connect_failure_becomes_a_connection_exception(bool $autoRetry): void
    {
        $client = new ApiClient([
            'client_id' => 'test-client',
            'rate_limit' => ['strategy' => 'exception', 'auto_retry' => $autoRetry],
        ]);
        $client->withToken(Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => implode(' ', Scope::all()),
        ]));

        $cause = new ConnectException('cURL error 28: Connection timed out', new Request('GET', 'profile'));
        $attempts = [];
        $this->mockInnermostHandler($client, [$cause], $attempts);

        try {
            $client->get('/profile');
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertInstanceOf(VaalApiException::class, $e);
            $this->assertSame($cause, $e->getPrevious());
            $this->assertSame('GGG API did not respond: cURL error 28: Connection timed out', $e->getMessage());
        }

        $this->assertCount(1, $attempts, 'A transfer failure is not retried');
    }
}
