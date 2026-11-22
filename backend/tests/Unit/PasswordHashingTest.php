<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PasswordHashingTest extends TestCase
{
    public function testArgon2idHashIsGenerated(): void
    {
        $password = 'SecurePassword123!';
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(password_verify($password, $hash));
    }

    public function testWrongPasswordFails(): void
    {
        $hash = password_hash('correct-password', PASSWORD_ARGON2ID);
        $this->assertFalse(password_verify('wrong-password', $hash));
    }

    public function testHashesAreUnique(): void
    {
        $password = 'same-password';
        $hash1 = password_hash($password, PASSWORD_ARGON2ID);
        $hash2 = password_hash($password, PASSWORD_ARGON2ID);
        $this->assertNotSame($hash1, $hash2);
    }

    public function testRandomTokensAreSecure(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokensAreUnique(): void
    {
        $token1 = bin2hex(random_bytes(32));
        $token2 = bin2hex(random_bytes(32));
        $this->assertNotSame($token1, $token2);
    }

    public function testHashedTokenForStorage(): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $storedHash = hash('sha256', $rawToken);
        $this->assertSame(64, strlen($storedHash));
        $this->assertTrue(hash_equals($storedHash, hash('sha256', $rawToken)));
    }
}
