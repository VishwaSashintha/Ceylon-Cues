<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Unit;

use CeylonCues\Services\Auth\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    private TotpService $totp;

    protected function setUp(): void
    {
        $this->totp = new TotpService();
    }

    public function testGenerateSecretReturns32CharBase32(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testGenerateSecretIsUnique(): void
    {
        $a = $this->totp->generateSecret();
        $b = $this->totp->generateSecret();
        $this->assertNotSame($a, $b);
    }

    public function testGenerateTotpReturnsSixDigits(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->generateTotp($secret);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testVerifyAcceptsCurrentWindowCode(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->generateTotp($secret);
        $this->assertTrue($this->totp->verify($secret, $code));
    }

    public function testVerifyRejectsInvalidCode(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verify($secret, '000000'));
    }

    public function testVerifyRejectsWrongFormat(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertFalse($this->totp->verify($secret, 'abcdef'));
    }

    public function testGenerateQrCodeUri(): void
    {
        $secret = $this->totp->generateSecret();
        $uri = $this->totp->generateQrCodeUri($secret, 'test@example.com');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=', $uri);
        $this->assertStringContainsString('issuer=CeylonCues', $uri);
    }
}
