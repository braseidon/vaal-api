<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\StashTab;
use PHPUnit\Framework\TestCase;

class StashTabTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/stash-detail.json'),
            true,
        );
    }

    public function testAccessors(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertSame('a1b2c3d4e5', $tab->id());
        $this->assertSame('Currency', $tab->name());
        $this->assertSame('CurrencyStash', $tab->type());
    }

    public function testItems(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertCount(2, $tab->items());
        $this->assertSame('Chaos Orb', $tab->items()[0]['typeLine']);
        $this->assertSame('Exalted Orb', $tab->items()[1]['typeLine']);
    }

    public function testMetadata(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertTrue($tab->metadata()['public']);
    }

    public function testRaw(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertSame($this->fixture, $tab->raw());
    }

    public function testEmptyDataDefaults(): void
    {
        $tab = StashTab::fromArray([]);

        $this->assertSame('', $tab->id());
        $this->assertSame('', $tab->name());
        $this->assertSame('', $tab->type());
        $this->assertSame([], $tab->items());
        $this->assertSame([], $tab->metadata());
    }
}
