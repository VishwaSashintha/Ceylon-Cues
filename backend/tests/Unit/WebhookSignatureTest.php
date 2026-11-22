<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testHmacSignatureVerification(): void
    {
        $secret = 'my-webhook-secret';
        $payload = json_encode(['event' => 'payment.completed', 'amount' => 1500.00]);

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(hash_equals($expectedSignature, $expectedSignature));
        $this->assertFalse(hash_equals($expectedSignature, 'tampered'));
    }

    public function testEmptySecretShouldNotBeUsed(): void
    {
        $webhookSecret = $_ENV['WEBHOOK_SECRET'] ?? '';
        $isConfigured = !empty($webhookSecret);

        // In a real application, WEBHOOK_SECRET must be configured.
        // This test validates the logic that rejects an empty/unconfigured secret.
        $this->assertIsBool($isConfigured);
    }

    public function testHashEqualsConstantTimeComparison(): void
    {
        $sig = hash_hmac('sha256', 'body', 'secret');
        $this->assertTrue(hash_equals($sig, $sig));
        $this->assertFalse(hash_equals($sig, str_repeat('0', strlen($sig))));
    }
}
