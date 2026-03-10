<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\League;
use PHPUnit\Framework\TestCase;

class LeagueTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/leagues.json'),
            true,
        );
    }

    public function testFromArrayStandardLeague(): void
    {
        $league = League::fromArray($this->fixture[0]);

        $this->assertSame('Standard', $league->id);
        $this->assertSame('pc', $league->realm);
        $this->assertSame('The default game mode.', $league->description);
        $this->assertNull($league->startAt);
        $this->assertNull($league->endAt);
        $this->assertSame([], $league->rules);
    }

    public function testFromArrayCurrentLeague(): void
    {
        $league = League::fromArray($this->fixture[1]);

        $this->assertSame('Mirage', $league->id);
        $this->assertSame('2026-03-07T20:00:00Z', $league->startAt);
        $this->assertSame('2026-06-08T20:00:00Z', $league->endAt);
        $this->assertStringContainsString('pathofexile.com/league/Mirage', $league->url);
    }

    public function testIsCurrentTrue(): void
    {
        $league = League::fromArray($this->fixture[1]);

        $this->assertTrue($league->isCurrent());
    }

    public function testIsCurrentFalseForStandard(): void
    {
        $league = League::fromArray($this->fixture[0]);

        $this->assertFalse($league->isCurrent());
    }

    public function testHardcoreRules(): void
    {
        $league = League::fromArray($this->fixture[2]);

        $this->assertSame('Hardcore Mirage', $league->id);
        $this->assertCount(1, $league->rules);
        $this->assertSame('Hardcore', $league->rules[0]['id']);
    }

    public function testToArray(): void
    {
        $league = League::fromArray($this->fixture[1]);
        $array  = $league->toArray();

        $this->assertSame('Mirage', $array['id']);
        $this->assertSame('pc', $array['realm']);
        $this->assertArrayHasKey('startAt', $array);
        $this->assertArrayHasKey('category', $array);
    }
}
