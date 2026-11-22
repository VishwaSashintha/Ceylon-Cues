<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Feature;

use CeylonCues\Core\Database;
use CeylonCues\Services\Auth\AuthService;
use CeylonCues\Services\Auth\TotpService;
use PHPUnit\Framework\TestCase;

final class AuthFeatureTest extends TestCase
{
    private \PDO $db;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->auth = new AuthService();
    }

    public function testCustomerRegistrationAndLogin(): void
    {
        $email = 'test_feature_' . uniqid() . '@example.com';
        $password = 'Secret123!';

        $user = $this->auth->register([
            'first_name'            => 'John',
            'last_name'             => 'Doe',
            'email'                 => $email,
            'phone'                 => '+94771234567',
            'password'              => $password,
            'password_confirmation' => $password,
        ]);

        $this->assertNotEmpty($user['id']);
        $this->assertSame($email, $user['email']);

        $loginResult = $this->auth->login($email, $password);
        $this->assertArrayHasKey('token', $loginResult);
        $this->assertArrayHasKey('user', $loginResult);

        $validatedUser = $this->auth->validateSession($loginResult['token']);
        $this->assertNotNull($validatedUser);
        $this->assertSame($user['id'], $validatedUser['id']);

        $logoutSuccess = $this->auth->logout($loginResult['token']);
        $this->assertTrue($logoutSuccess);

        $this->assertNull($this->auth->validateSession($loginResult['token']));
    }

    public function testTwoFactorAuthenticationFlow(): void
    {
        $email = 'test_2fa_' . uniqid() . '@example.com';
        $password = 'Secret123!';

        $user = $this->auth->register([
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'email'                 => $email,
            'phone'                 => '+94779876543',
            'password'              => $password,
            'password_confirmation' => $password,
        ]);

        $setup = $this->auth->setup2fa((int)$user['id']);
        $this->assertArrayHasKey('secret', $setup);

        $totp = new TotpService();
        $code = $totp->generateTotp($setup['secret']);

        $this->auth->enable2fa((int)$user['id'], $code);

        $loginResult = $this->auth->login($email, $password);
        $this->assertTrue($loginResult['requires_2fa'] ?? false);
        $this->assertNotEmpty($loginResult['temp_token'] ?? null);

        $code2 = $totp->generateTotp($setup['secret']);
        $finalLogin = $this->auth->loginWith2fa($loginResult['temp_token'], $code2);
        $this->assertArrayHasKey('token', $finalLogin);
        $this->assertArrayHasKey('user', $finalLogin);

        $this->auth->disable2fa((int)$user['id'], $code2);
    }
}
