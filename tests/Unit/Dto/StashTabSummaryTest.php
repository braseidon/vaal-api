<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\StashTabSummary;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are trimmed real GGG responses:
 * - stash-list.json: GET /stash/<league>, {"stashes": [...]}, flat (the list never fills `children`)
 * - stash-detail-children.json: GET /stash/<league>/<id> on a Breach tab, {"stash": {...}};
 *   its `children` carry `parent`, the only place a captured child tab exists
 */
class StashTabSummaryTest extends TestCase
{
    private array $list;

    private array $container;

    protected function setUp(): void
    {
        $this->list = json_decode(file_get_contents(__DIR__.'/../../fixtures/stash-list.json'), true)['stashes'];
        $this->container = json_decode(file_get_contents(__DIR__.'/../../fixtures/stash-detail-children.json'), true)['stash'];
    }

    public function test_reads_the_oauth_field_names(): void
    {
        $tab = StashTabSummary::fromArray($this->list[0]);

        $this->assertSame('a01ab2c0b4', $tab->id);
        $this->assertSame('$━━━━━━$', $tab->name);
        $this->assertSame('CurrencyStash', $tab->type);
        $this->assertSame(0, $tab->index);
        $this->assertSame(8, StashTabSummary::fromArray($this->list[3])->index);
    }

    public function test_color_reads_metadata_colour(): void
    {
        // GGG nests the hex colour under metadata; there is no top-level colour field
        $this->assertSame('80ff80', StashTabSummary::fromArray($this->list[0])->color);
        $this->assertSame('ffd500', StashTabSummary::fromArray($this->list[2])->color);
    }

    public function test_is_public_true(): void
    {
        $this->assertTrue(StashTabSummary::fromArray($this->list[2])->isPublic());
    }

    public function test_is_public_false_when_absent(): void
    {
        $this->assertFalse(StashTabSummary::fromArray($this->list[0])->isPublic());
    }

    public function test_top_level_tab_has_no_parent_and_no_folder(): void
    {
        $tab = StashTabSummary::fromArray($this->list[1]);

        $this->assertNull($tab->parent);
        $this->assertNull($tab->folder);
        $this->assertFalse($tab->isFolder());
        $this->assertSame([], $tab->children);
    }

    public function test_child_tab_reads_its_parent_id(): void
    {
        $tab = StashTabSummary::fromArray($this->container);

        $this->assertCount(2, $tab->children);
        $this->assertSame('ee142785b0', $tab->children[0]->id);
        $this->assertSame('87ae83d269', $tab->children[0]->parent);
        $this->assertSame('BreachStash', $tab->children[0]->type);
        $this->assertNull($tab->parent);
    }

    public function test_folder_fields_follow_the_upstream_type(): void
    {
        // No captured list holds a folder. Shape from GGG's reference (object StashTab):
        // top-level `folder` is the containing folder's id, `metadata.folder` marks the folder itself.
        $folder = StashTabSummary::fromArray([
            'id' => '1111111111', 'name' => 'F', 'type' => 'Folder', 'index' => 3,
            'metadata' => ['folder' => true, 'colour' => 'ffffff'],
        ]);
        $inFolder = StashTabSummary::fromArray([
            'id' => '2222222222', 'folder' => '1111111111', 'name' => 'T', 'type' => 'PremiumStash', 'index' => 4,
            'metadata' => ['colour' => 'ffffff'],
        ]);

        $this->assertTrue($folder->isFolder());
        $this->assertNull($folder->folder);
        $this->assertFalse($inFolder->isFolder());
        $this->assertSame('1111111111', $inFolder->folder);
    }

    public function test_to_array_round_trips_through_from_array(): void
    {
        // The app caches toArray() output and rebuilds the DTO with fromArray() (GggApiService::stashes)
        $tab = StashTabSummary::fromArray($this->container);
        $again = StashTabSummary::fromArray($tab->toArray());

        $this->assertEquals($tab, $again);
        $this->assertSame('2c0059', $again->color);
        $this->assertSame('87ae83d269', $again->children[0]->parent);
    }
}
