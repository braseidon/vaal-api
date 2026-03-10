<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\Character;
use PHPUnit\Framework\TestCase;

class CharacterTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/character-detail.json'),
            true,
        );
    }

    public function testBasicAccessors(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame('VaalSlamDancer', $char->name());
        $this->assertSame('Occultist', $char->class());
        $this->assertSame('Standard', $char->league());
        $this->assertSame(100, $char->level());
    }

    public function testEquipment(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertCount(1, $char->equipment());
        $this->assertSame('Test Helm', $char->equipment()[0]['name']);
    }

    public function testPassives(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame([4036, 4367, 12926], $char->passiveHashes());
        $this->assertSame([], $char->passiveHashesEx());
        $this->assertSame(['56128' => 42540], $char->masteryEffects());
        $this->assertSame('Eramir', $char->banditChoice());
        $this->assertSame('TheBrineKing', $char->pantheonMajor());
        $this->assertSame('Abberath', $char->pantheonMinor());
    }

    public function testRaw(): void
    {
        $char = Character::fromArray($this->fixture);

        $this->assertSame($this->fixture, $char->raw());
    }
}
