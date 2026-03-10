<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\CharacterSummary;
use PHPUnit\Framework\TestCase;

class CharacterSummaryTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/character-list.json'),
            true,
        );
    }

    public function testFromArray(): void
    {
        $char = CharacterSummary::fromArray($this->fixture['characters'][0]);

        $this->assertSame('VaalSlamDancer', $char->name);
        $this->assertSame('Occultist', $char->class);
        $this->assertSame('Standard', $char->league);
        $this->assertSame(100, $char->level);
        $this->assertSame(4250334444, $char->experience);
        $this->assertFalse($char->current);
    }

    public function testCurrentCharacter(): void
    {
        $char = CharacterSummary::fromArray($this->fixture['characters'][1]);

        $this->assertSame('MirageLeagueStarter', $char->name);
        $this->assertTrue($char->current);
    }

    public function testToArray(): void
    {
        $char  = CharacterSummary::fromArray($this->fixture['characters'][0]);
        $array = $char->toArray();

        $this->assertSame('VaalSlamDancer', $array['name']);
        $this->assertSame('Occultist', $array['class']);
        $this->assertSame(100, $array['level']);
    }
}
