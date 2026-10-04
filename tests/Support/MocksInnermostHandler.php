<?php

namespace Braseidon\VaalApi\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

/**
 * Swap only the network end of a client's Guzzle stack.
 *
 * ApiClient and PublicApiClient build their own HandlerStack in the
 * constructor (retry middleware, base URI, default headers). Replacing the
 * whole Guzzle client by reflection would test none of that, so this keeps the
 * constructor's client and replaces only its innermost handler with a
 * MockHandler.
 *
 * Every attempt that reaches the network end is recorded, retries included.
 * The `delay` option Guzzle's retry middleware sets is recorded and then
 * dropped, so a test reads the backoff it asked for instead of sleeping it.
 */
trait MocksInnermostHandler
{
    /**
     * @param  object  $client  An ApiClient or PublicApiClient built through its constructor
     * @param  array<int, mixed>  $responses  Responses, throwables or callables for the MockHandler
     * @param  array<int, array{request: RequestInterface, delay: int|float|null}>  $attempts  Filled as requests arrive
     */
    private function mockInnermostHandler(object $client, array $responses, array &$attempts): MockHandler
    {
        $httpClient = (new \ReflectionProperty($client, 'httpClient'))->getValue($client);
        $this->assertInstanceOf(GuzzleClient::class, $httpClient);

        $stack = $httpClient->getConfig('handler');
        $this->assertInstanceOf(HandlerStack::class, $stack);

        $mock = new MockHandler($responses);
        $stack->setHandler($mock);

        // Pushed last, so it sits between every other middleware and the mock.
        $stack->push(function (callable $handler) use (&$attempts): callable {
            return function (RequestInterface $request, array $options) use ($handler, &$attempts) {
                $attempts[] = ['request' => $request, 'delay' => $options['delay'] ?? null];
                unset($options['delay']);

                return $handler($request, $options);
            };
        }, 'record_attempts');

        return $mock;
    }
}
