<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\PathOfExileResourceOwner;
use PHPUnit\Framework\TestCase;

class ResourceOwnerTest extends TestCase
{
    public function test_get_id(): void
    {
        $owner = new PathOfExileResourceOwner([
            'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'name' => 'TestPlayer#1234',
        ]);

        $this->assertSame('a1b2c3d4-e5f6-7890-abcd-ef1234567890', $owner->getId());
    }

    public function test_get_name(): void
    {
        $owner = new PathOfExileResourceOwner(['name' => 'ExileRunner#4821']);

        $this->assertSame('ExileRunner#4821', $owner->getName());
    }

    public function test_get_realm(): void
    {
        $owner = new PathOfExileResourceOwner(['realm' => 'pc']);

        $this->assertSame('pc', $owner->getRealm());
    }

    public function test_get_locale(): void
    {
        $owner = new PathOfExileResourceOwner(['locale' => 'en_US']);

        $this->assertSame('en_US', $owner->getLocale());
    }

    public function test_null_defaults(): void
    {
        $owner = new PathOfExileResourceOwner([]);

        $this->assertSame('', $owner->getId());
        $this->assertSame('', $owner->getName());
        $this->assertNull($owner->getRealm());
        $this->assertNull($owner->getLocale());
    }

    public function test_to_array(): void
    {
        $data = ['uuid' => 'abc', 'name' => 'Test#1', 'realm' => 'pc'];
        $owner = new PathOfExileResourceOwner($data);

        $this->assertSame($data, $owner->toArray());
    }
}
