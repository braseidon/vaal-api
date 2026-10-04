<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\League;
use PHPUnit\Framework\TestCase;

/**
 * leagues.json is GET /league's shape, {"leagues": [...]}, holding four real
 * League objects from the account-leagues.json capture (same GGG type).
 */
class LeagueTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/leagues.json'),
            true,
        )['leagues'];
    }

    public function test_from_array_standard_league(): void
    {
        $league = League::fromArray($this->fixture[0]);

        $this->assertSame('Standard', $league->id);
        $this->assertSame('pc', $league->realm);
        $this->assertSame('The default game mode.', $league->description);
        $this->assertSame('2013-01-23T21:00:00Z', $league->startAt);
        $this->assertNull($league->endAt);
        $this->assertSame([], $league->rules);
        $this->assertFalse($league->isCurrent());
    }

    public function test_current_league(): void
    {
        $league = League::fromArray($this->fixture[2]);

        $this->assertSame('Allflame', $league->id);
        $this->assertSame('2026-07-24T20:00:00Z', $league->startAt);
        $this->assertTrue($league->isCurrent());
    }

    public function test_hardcore_rules(): void
    {
        $league = League::fromArray($this->fixture[3]);

        $this->assertSame('Hardcore Allflame', $league->id);
        $this->assertSame('Hardcore', $league->rules[0]['id']);
    }

    public function test_to_array(): void
    {
        $array = League::fromArray($this->fixture[2])->toArray();

        $this->assertSame('Allflame', $array['id']);
        $this->assertSame('pc', $array['realm']);
        $this->assertTrue($array['category']['current']);
    }
}
