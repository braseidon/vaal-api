<?php

namespace Braseidon\VaalApi\Tests\Unit\Auth;

use Braseidon\VaalApi\Auth\Token;
use Braseidon\VaalApi\Enums\Scope;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    public function test_from_array(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => time() + 3600,
            'scope' => 'account:profile account:characters',
            'username' => 'TestUser#1234',
            'sub' => 'uuid-123',
        ]);

        $this->assertSame('test-access-token', $token->accessToken);
        $this->assertSame('test-refresh-token', $token->refreshToken);
        $this->assertSame('account:profile account:characters', $token->scope);
        $this->assertSame('TestUser#1234', $token->username);
        $this->assertSame('uuid-123', $token->sub);
    }

    public function test_to_array(): void
    {
        $data = [
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => 1700000000,
            'scope' => 'account:profile',
            'username' => 'TestUser#1234',
            'sub' => 'uuid-123',
        ];

        $token = Token::fromArray($data);
        $this->assertSame($data, $token->toArray());
    }

    public function test_is_expired(): void
    {
        $expired = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() - 100,
        ]);

        $valid = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 3600,
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
    }

    public function test_needs_refresh(): void
    {
        $soonExpiring = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 10,
        ]);

        $fresh = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 3600,
        ]);

        $this->assertTrue($soonExpiring->needsRefresh(300));
        $this->assertFalse($fresh->needsRefresh(300));
    }

    public function test_needs_refresh_custom_buffer(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 60,
        ]);

        $this->assertTrue($token->needsRefresh(120));
        $this->assertFalse($token->needsRefresh(30));
    }

    public function test_has_scope_with_string(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 3600,
            'scope' => 'account:profile account:characters',
        ]);

        $this->assertTrue($token->hasScope('account:profile'));
        $this->assertTrue($token->hasScope('account:characters'));
        $this->assertFalse($token->hasScope('account:stashes'));
    }

    public function test_has_scope_with_enum(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 3600,
            'scope' => 'account:profile account:stashes',
        ]);

        $this->assertTrue($token->hasScope(Scope::Profile));
        $this->assertTrue($token->hasScope(Scope::Stashes));
        $this->assertFalse($token->hasScope(Scope::Characters));
    }

    public function test_has_scope_with_empty_scope(): void
    {
        $token = Token::fromArray([
            'access_token' => 'test',
            'expires_at' => time() + 3600,
            'scope' => '',
        ]);

        $this->assertFalse($token->hasScope(Scope::Profile));
        $this->assertFalse($token->hasScope('account:profile'));
    }

    public function test_has_scope_matches_whole_scopes_only(): void
    {
        // service:leagues is a prefix of service:leagues:ladder, and neither implies the other.
        $ladderOnly = Token::fromArray(['access_token' => 'test', 'scope' => 'service:leagues:ladder']);
        $leaguesOnly = Token::fromArray(['access_token' => 'test', 'scope' => 'service:leagues']);

        $this->assertFalse($ladderOnly->hasScope(Scope::ServiceLeagues));
        $this->assertTrue($ladderOnly->hasScope(Scope::ServiceLeaguesLadder));
        $this->assertFalse($leaguesOnly->hasScope(Scope::ServiceLeaguesLadder));
        $this->assertTrue($leaguesOnly->hasScope(Scope::ServiceLeagues));
    }

    public function test_from_access_token_keeps_every_field_ggg_sends(): void
    {
        $accessToken = new AccessToken([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires' => 1900000000,
            'scope' => 'account:profile account:characters',
            'username' => 'Exile#1234',
            'sub' => 'uuid-123',
        ]);

        $token = Token::fromAccessToken($accessToken);

        $this->assertSame('new-access-token', $token->accessToken);
        $this->assertSame('new-refresh-token', $token->refreshToken);
        $this->assertSame(1900000000, $token->expiresAt);
        $this->assertSame('account:profile account:characters', $token->scope);
        $this->assertSame('Exile#1234', $token->username);
        $this->assertSame('uuid-123', $token->sub);
    }

    public function test_debug_output_masks_both_token_strings(): void
    {
        $token = new Token('secret-access', 'secret-refresh', time() + 3600, 'account:profile', 'Player#1234');

        foreach ([print_r($token, true), $this->varDump($token)] as $output) {
            $this->assertStringNotContainsString('secret-access', $output);
            $this->assertStringNotContainsString('secret-refresh', $output);
            $this->assertStringContainsString('Player#1234', $output);
        }
    }

    public function test_the_token_strings_are_kept_out_of_stack_traces(): void
    {
        foreach ([[Token::class, '__construct', ['accessToken', 'refreshToken']], [Token::class, 'fromArray', ['data']]] as [$class, $method, $names]) {
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $names, true)) {
                    $this->assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class), "{$method}(\${$parameter->getName()})");
                }
            }
        }
    }

    private function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
