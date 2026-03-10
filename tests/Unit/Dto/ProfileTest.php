<?php

namespace Braseidon\VaalApi\Tests\Unit\Dto;

use Braseidon\VaalApi\Dto\Profile;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        $this->fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/profile.json'),
            true,
        );
    }

    public function testFromArray(): void
    {
        $profile = Profile::fromArray($this->fixture);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $profile->uuid);
        $this->assertSame('ExileRunner#4821', $profile->name);
        $this->assertNull($profile->locale);
        $this->assertSame(['name' => 'exile_runner_ttv'], $profile->twitch);
        $this->assertNull($profile->guild);
    }

    public function testToArray(): void
    {
        $profile = Profile::fromArray($this->fixture);
        $array   = $profile->toArray();

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $array['uuid']);
        $this->assertSame('ExileRunner#4821', $array['name']);
        $this->assertArrayHasKey('twitch', $array);
        // Null values are filtered out by toArray()
        $this->assertArrayNotHasKey('locale', $array);
        $this->assertArrayNotHasKey('guild', $array);
    }
}
