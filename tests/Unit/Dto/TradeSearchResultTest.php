<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\TradeSearchResult;
use PHPUnit\Framework\TestCase;

class TradeSearchResultTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/trade-search.json'),
            true,
        );
    }

    public function testFromArray(): void
    {
        $result = TradeSearchResult::fromArray($this->fixture);

        $this->assertSame('Xb7Kp9Q', $result->id);
        $this->assertCount(5, $result->result);
        $this->assertSame(847, $result->total);
        $this->assertSame(35, $result->complexity);
    }

    public function testResultHashesAreStrings(): void
    {
        $result = TradeSearchResult::fromArray($this->fixture);

        foreach ($result->result as $hash) {
            $this->assertIsString($hash);
            $this->assertSame(40, strlen($hash));
        }
    }

    public function testDefaultsForMissingFields(): void
    {
        $result = TradeSearchResult::fromArray(['id' => 'abc']);

        $this->assertSame('abc', $result->id);
        $this->assertSame([], $result->result);
        $this->assertSame(0, $result->total);
        $this->assertNull($result->complexity);
    }
}
