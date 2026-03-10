<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Enums\Scope;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    public function testFromArray(): void
    {
        $token = Token::fromArray([
            'access_token'  => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at'    => time() + 3600,
            'scope'         => 'account:profile account:characters',
            'username'      => 'TestUser#1234',
            'sub'           => 'uuid-123',
        ]);

        $this->assertSame('test-access-token', $token->accessToken);
        $this->assertSame('test-refresh-token', $token->refreshToken);
        $this->assertSame('account:profile account:characters', $token->scope);
        $this->assertSame('TestUser#1234', $token->username);
        $this->assertSame('uuid-123', $token->sub);
    }

    public function testToArray(): void
    {
        $data = [
            'access_token'  => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at'    => 1700000000,
            'scope'         => 'account:profile',
            'username'      => 'TestUser#1234',
            'sub'           => 'uuid-123',
        ];

        $token = Token::fromArray($data);
        $this->assertSame($data, $token->toArray());
    }

    public function testIsExpired(): void
    {
        $expired = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() - 100,
        ]);

        $valid = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 3600,
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
    }

    public function testNeedsRefresh(): void
    {
        $soonExpiring = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 10,
        ]);

        $fresh = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 3600,
        ]);

        $this->assertTrue($soonExpiring->needsRefresh(300));
        $this->assertFalse($fresh->needsRefresh(300));
    }

    public function testNeedsRefreshCustomBuffer(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 60,
        ]);

        $this->assertTrue($token->needsRefresh(120));
        $this->assertFalse($token->needsRefresh(30));
    }

    public function testHasScopeWithString(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 3600,
            'scope'        => 'account:profile account:characters',
        ]);

        $this->assertTrue($token->hasScope('account:profile'));
        $this->assertTrue($token->hasScope('account:characters'));
        $this->assertFalse($token->hasScope('account:stashes'));
    }

    public function testHasScopeWithEnum(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 3600,
            'scope'        => 'account:profile account:stashes',
        ]);

        $this->assertTrue($token->hasScope(Scope::Profile));
        $this->assertTrue($token->hasScope(Scope::Stashes));
        $this->assertFalse($token->hasScope(Scope::Characters));
    }

    public function testHasScopeWithEmptyScope(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at'   => time() + 3600,
            'scope'        => '',
        ]);

        $this->assertFalse($token->hasScope(Scope::Profile));
        $this->assertFalse($token->hasScope('account:profile'));
    }
}
