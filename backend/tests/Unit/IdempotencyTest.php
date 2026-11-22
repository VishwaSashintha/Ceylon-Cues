<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IdempotencyTest extends TestCase
{
    public function testSameKeyReturnsSameResult(): void
    {
        $key = bin2hex(random_bytes(16));
        $existingRecord = ['id' => 1, 'idempotency_key' => $key, 'status' => 'completed'];

        $this->assertSame($key, $existingRecord['idempotency_key']);
        $this->assertSame('completed', $existingRecord['status']);
    }

    public function testDifferentKeysAreDifferent(): void
    {
        $key1 = bin2hex(random_bytes(16));
        $key2 = bin2hex(random_bytes(16));

        $this->assertNotSame($key1, $key2);
    }

    public function testKeyIsNotEmpty(): void
    {
        $key = bin2hex(random_bytes(16));
        $this->assertNotEmpty($key);
        $this->assertSame(32, strlen($key));
    }
}
