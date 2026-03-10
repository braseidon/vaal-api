<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\PathOfExileResourceOwner;
use PHPUnit\Framework\TestCase;

class ResourceOwnerTest extends TestCase
{
    public function testGetId(): void
    {
        $owner = new PathOfExileResourceOwner([
            'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'name' => 'TestPlayer#1234',
        ]);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $owner->getId());
    }

    public function testGetName(): void
    {
        $owner = new PathOfExileResourceOwner(['name' => 'ExileRunner#4821']);

        $this->assertSame('ExileRunner#4821', $owner->getName());
    }

    public function testGetRealm(): void
    {
        $owner = new PathOfExileResourceOwner(['realm' => 'pc']);

        $this->assertSame('pc', $owner->getRealm());
    }

    public function testGetLocale(): void
    {
        $owner = new PathOfExileResourceOwner(['locale' => 'en_US']);

        $this->assertSame('en_US', $owner->getLocale());
    }

    public function testNullDefaults(): void
    {
        $owner = new PathOfExileResourceOwner([]);

        $this->assertSame('', $owner->getId());
        $this->assertSame('', $owner->getName());
        $this->assertNull($owner->getRealm());
        $this->assertNull($owner->getLocale());
    }

    public function testToArray(): void
    {
        $data  = ['uuid' => 'abc', 'name' => 'Test#1', 'realm' => 'pc'];
        $owner = new PathOfExileResourceOwner($data);

        $this->assertSame($data, $owner->toArray());
    }
}
