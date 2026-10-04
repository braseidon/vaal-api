<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\Character;
use PHPUnit\Framework\TestCase;

/**
 * character-detail.json is a trimmed real GET /character/<name> response:
 * GGG wraps the character, {"character": {...}}. The DTO holds the whole
 * response (raw() is what the app caches and returns to the planner) and its
 * accessors read inside the `character` key.
 */
class CharacterTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/character-detail.json'),
            true,
        );
    }

    public function test_basic_accessors(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame('4124747963f8a9588d9464cedf981d4f42da371d5bbbdf550ecc54c50f16cca0', $char->id());
        $this->assertSame('MagicFindDotGG', $char->name());
        $this->assertSame('Luminary', $char->class());
        $this->assertSame('Allflame', $char->league());
        $this->assertSame(95, $char->level());
        $this->assertSame(3073586588, $char->experience());
    }

    public function test_equipment(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertCount(1, $char->equipment());
        $this->assertSame('Blight Guardian', $char->equipment()[0]['name']);
        $this->assertSame([], $char->inventory());
        $this->assertSame([], $char->rucksack());
    }

    public function test_passives(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame([918, 1593, 1977, 3452], $char->passiveHashes());
        $this->assertSame([], $char->passiveHashesEx());
        $this->assertSame([47197 => 23621, 25535 => 30612], $char->masteryEffects());
        $this->assertSame('Eramir', $char->banditChoice());
        $this->assertSame('TheBrineKing', $char->pantheonMajor());
        $this->assertSame('Abberath', $char->pantheonMinor());
        $this->assertNull($char->alternateAscendancy());
    }

    public function test_raw_is_the_whole_wrapped_response(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame($this->fixture, $char->raw());
        $this->assertSame('MagicFindDotGG', $char->raw()['character']['name']);
    }

    public function test_empty_response_defaults(): void
    {
        $char = Character::fromArray([]);

        $this->assertSame('', $char->name());
        $this->assertSame(0, $char->level());
        $this->assertNull($char->league());
        $this->assertSame([], $char->passiveHashes());
    }
}
