<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\StashTab;
use PHPUnit\Framework\TestCase;

/**
 * stash-detail.json is a trimmed real GET /stash/<league>/<id> response,
 * {"stash": {...}}; StashResource unwraps `stash` before building the DTO.
 */
class StashTabTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/stash-detail.json'),
            true,
        )['stash'];
    }

    public function test_accessors(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertSame('eeeac0167f', $tab->id());
        $this->assertSame('· Essence', $tab->name());
        $this->assertSame('EssenceStash', $tab->type());
    }

    public function test_items(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertCount(2, $tab->items());
        $this->assertSame('Screaming Essence of Anger', $tab->items()[0]['typeLine']);
        $this->assertSame(3, $tab->items()[0]['stackSize']);
        $this->assertSame('Weeping Essence of Contempt', $tab->items()[1]['typeLine']);
    }

    public function test_metadata(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertSame('2c0059', $tab->metadata()['colour']);
    }

    public function test_raw(): void
    {
        $tab = StashTab::fromArray($this->fixture);

        $this->assertSame($this->fixture, $tab->raw());
    }

    public function test_empty_data_defaults(): void
    {
        $tab = StashTab::fromArray([]);

        $this->assertSame('', $tab->id());
        $this->assertSame('', $tab->name());
        $this->assertSame('', $tab->type());
        $this->assertSame([], $tab->items());
        $this->assertSame([], $tab->metadata());
    }
}
