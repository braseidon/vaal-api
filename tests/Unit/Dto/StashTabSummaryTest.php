<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\StashTabSummary;
use PHPUnit\Framework\TestCase;

class StashTabSummaryTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/stash-list.json'),
            true,
        );
    }

    public function testFromArrayBasic(): void
    {
        $tab = StashTabSummary::fromArray($this->fixture['stashes'][0]);

        $this->assertSame('a1b2c3d4e5', $tab->id);
        $this->assertSame('Currency', $tab->name);
        $this->assertSame('CurrencyStash', $tab->type);
        $this->assertSame(0, $tab->index);
        $this->assertSame('cc9900', $tab->color);
    }

    public function testAbbreviatedFieldNames(): void
    {
        // 'n' maps to name, 'i' maps to index, 'colour' maps to color
        $tab = StashTabSummary::fromArray($this->fixture['stashes'][1]);

        $this->assertSame('Maps', $tab->name);
        $this->assertSame(1, $tab->index);
        $this->assertSame('336699', $tab->color);
    }

    public function testIsPublicTrue(): void
    {
        $tab = StashTabSummary::fromArray($this->fixture['stashes'][0]);

        $this->assertTrue($tab->isPublic());
    }

    public function testIsPublicFalseWhenAbsent(): void
    {
        $tab = StashTabSummary::fromArray($this->fixture['stashes'][1]);

        $this->assertFalse($tab->isPublic());
    }

    public function testFolderWithChildren(): void
    {
        $tab = StashTabSummary::fromArray($this->fixture['stashes'][2]);

        $this->assertTrue($tab->folder);
        $this->assertCount(1, $tab->children);
        $this->assertInstanceOf(StashTabSummary::class, $tab->children[0]);
        $this->assertSame('Dump Tab', $tab->children[0]->name);
        $this->assertSame('QuadStash', $tab->children[0]->type);
    }

    public function testToArray(): void
    {
        $tab   = StashTabSummary::fromArray($this->fixture['stashes'][2]);
        $array = $tab->toArray();

        $this->assertSame('Tabs', $array['name']);
        $this->assertSame('Folder', $array['type']);
        $this->assertCount(1, $array['children']);
        $this->assertSame('Dump Tab', $array['children'][0]['name']);
    }
}
