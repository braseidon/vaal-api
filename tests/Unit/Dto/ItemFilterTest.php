<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\ItemFilter;
use PHPUnit\Framework\TestCase;

class ItemFilterTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/item-filter.json'),
            true,
        );
    }

    public function testFromArray(): void
    {
        $filter = ItemFilter::fromArray($this->fixture);

        $this->assertSame('abc123def456', $filter->id);
        $this->assertSame('MF Strictness Filter', $filter->name);
        $this->assertSame('pc', $filter->realm);
        $this->assertSame('Custom loot filter for magic find builds', $filter->description);
        $this->assertSame('3.28.0', $filter->version);
        $this->assertSame('Normal', $filter->type);
        $this->assertTrue($filter->public);
        $this->assertStringContainsString('Rarity Unique', $filter->filter);
    }

    public function testFilterNameFieldMapping(): void
    {
        // GGG uses 'filter_name' in responses
        $data = ['id' => 'x', 'filter_name' => 'My Filter'];

        $filter = ItemFilter::fromArray($data);

        $this->assertSame('My Filter', $filter->name);
    }

    public function testToArray(): void
    {
        $filter = ItemFilter::fromArray($this->fixture);
        $array  = $filter->toArray();

        $this->assertSame('abc123def456', $array['id']);
        $this->assertSame('MF Strictness Filter', $array['filter_name']);
        $this->assertSame('pc', $array['realm']);
        $this->assertTrue($array['public']);
    }

    public function testDefaultValues(): void
    {
        $filter = ItemFilter::fromArray(['id' => 'test']);

        $this->assertSame('test', $filter->id);
        $this->assertSame('', $filter->name);
        $this->assertSame('pc', $filter->realm);
        $this->assertNull($filter->description);
        $this->assertFalse($filter->public);
        $this->assertNull($filter->filter);
    }
}
