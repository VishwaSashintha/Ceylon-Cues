<?php

declare(strict_types=1);

namespace CeylonCues\Services\Auth;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Authentication service handling registration, login, session management,
 * and password operations.
 *
 * All passwords are hashed with PASSWORD_ARGON2ID.
 * Sessions use 64-byte random hex tokens stored in the database.
 */
final class AuthService
{
    private PDO $db;

    /** Session lifetime in minutes */
    private int $sessionLifetime;

    /** Max login attempts before lockout */
    private int $maxLoginAttempts;

    /** Login lockout window in seconds */
    private int $loginWindow;

    /** Password reset token lifetime in minutes */
    private const PASSWORD_RESET_LIFETIME = 60;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->sessionLifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? 120);
        $this->maxLoginAttempts = (int) ($_ENV['RATE_LIMIT_LOGIN_MAX'] ?? 5);
        $this->loginWindow = (int) ($_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? 900);
    }

    /**
     * Register a new customer account.
     *
     * Only creates accounts with role='customer'. Admin/employee accounts
     * are created by the owner via EmployeeController.
     *
     * @param array $data Registration data with keys:
     *   first_name, last_name, email, phone, password, password_confirmation
     * @return array The created user record (without password hash)
     * @throws InvalidArgumentException On validation failure
     * @throws RuntimeException On duplicate email
     */
    public function register(array $data): array
    {
        $this->validateRegistration($data);

        // Check if email already exists
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => strtolower(trim($data['email']))]);
        if ($stmt->fetch()) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $uuid = Uuid::v4();
        $email = strtolower(trim($data['email']));
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $sql = "INSERT INTO users (uuid, first_name, last_name, email, phone, password_hash, role, is_active, created_at, updated_at)
                VALUES (:uuid, :first_name, :last_name, :email, :phone, :password_hash, 'customer', 1, NOW(), NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uuid'          => $uuid,
            ':first_name'    => trim($data['first_name']),
            ':last_name'     => trim($data['last_name']),
            ':email'         => $email,
            ':phone'         => trim($data['phone'] ?? ''),
            ':password_hash' => $passwordHash,
        ]);

        $userId = (int) $this->db->lastInsertId();

        return $this->getUserById($userId);
    }

    /**
     * Create and store a new active session token for the given user.
     *
     * Stores hash('sha256', $token) in the database (matching login() and validateSession()).
     * Returns the raw token to the caller, which must be sent as the Bearer token.
     */
    public function createSession(int $userId): string
    {
        $token     = bin2hex(random_bytes(32)); // 64 hex chars — raw token returned to caller
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->sessionLifetime * 60));
        $ip        = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'API/POS Client';

        $this->db->prepare(
            'INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at, created_at)
             VALUES (:user_id, :token, :ip, :user_agent, :expires_at, NOW())'
        )->execute([
            ':user_id'    => $userId,
            ':token'      => hash('sha256', $token), // stored as SHA-256 hash — same as login()
            ':ip'         => substr((string)$ip, 0, 45),
            ':user_agent' => substr((string)$userAgent, 0, 255),
            ':expires_at' => $expiresAt,
        ]);

        return $token; // return raw token; caller sends it as Authorization: Bearer <token>
    }

    /**
     * Authenticate a user with email and password.
     *
     * @param string $email
     * @param string $password
     * @return array{user: array, token: string}
     * @throws RuntimeException On authentication failure
     */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));

        // Check rate limiting
        if ($this->isLoginRateLimited($email)) {
            throw new RuntimeException('Too many login attempts. Please try again later.');
        }

        // Find user by email
        $stmt = $this->db->prepare(
            'SELECT id, uuid, first_name, last_name, email, phone, password_hash, role, is_owner, is_active,
                    two_factor_enabled, created_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->recordLoginAttempt($email, false);
            throw new RuntimeException('Invalid email or password.');
        }

        if (!$user['is_active']) {
            $this->recordLoginAttempt($email, false);
            throw new RuntimeException('Your account has been deactivated. Please contact support.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt($email, false);
            throw new RuntimeException('Invalid email or password.');
        }

        // Successful login — record and clear failed attempts
        $this->recordLoginAttempt($email, true);

        // Check if password needs rehash
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 1,
            ]);
            $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => $newHash, ':id' => $user['id']]);
        }

        // If 2FA is enabled, issue a short-lived temp token instead of a session
        if (!empty($user['two_factor_enabled'])) {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS temp_2fa_tokens (
                    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id    INT UNSIGNED NOT NULL,
                    token      VARCHAR(64)  NOT NULL,
                    expires_at DATETIME     NOT NULL,
                    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user  (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $tempToken = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

            $this->db->prepare(
                'INSERT INTO temp_2fa_tokens (user_id, token, expires_at) VALUES (:uid, :tok, :exp)'
            )->execute([
                ':uid' => $user['id'],
                ':tok' => $tempToken,
                ':exp' => $expiresAt,
            ]);

            return [
                'requires_2fa' => true,
                'temp_token'   => $tempToken,
            ];
        }

        // Create session token
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->sessionLifetime * 60));

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->db->prepare(
            'INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at, created_at)
             VALUES (:user_id, :token, :ip, :user_agent, :expires_at, NOW())'
        )->execute([
            ':user_id'    => $user['id'],
            ':token'      => hash('sha256', $token),
            ':ip'         => $ip,
            ':user_agent' => substr($userAgent, 0, 512),
            ':expires_at' => $expiresAt,
        ]);

        // Update last login
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        unset($user['password_hash']);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Invalidate a session token (logout).
     *
     * @param string $token The raw (unhashed) session token
     * @return bool True if a session was invalidated
     */
    public function logout(string $token): bool
    {
        $hashedToken = hash('sha256', $token);
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE token = :token');
        $stmt->execute([':token' => $hashedToken]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Validate a session token and return the associated user data.
     *
     * @param string $token The raw (unhashed) session token
     * @return array|null User data if valid, null otherwise
     */
    public function validateSession(string $token): ?array
    {
        $hashedToken = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'SELECT s.user_id, s.expires_at,
                    u.id, u.uuid, u.first_name, u.last_name, u.email, u.phone,
                    u.role, u.is_owner, u.is_active, u.created_at
             FROM sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token = :token AND s.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $hashedToken]);
        $row = $stmt->fetch();

        if (!$row || !$row['is_active']) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'uuid'       => $row['uuid'],
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'email'      => $row['email'],
            'phone'      => $row['phone'],
            'role'       => $row['role'],
            'is_owner'   => (bool) $row['is_owner'],
            'is_active'  => (bool) $row['is_active'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * Change a user's password.
     *
     * @param int    $userId       User ID
     * @param string $oldPassword  Current password
     * @param string $newPassword  New password
     * @return bool
     * @throws RuntimeException If old password is incorrect or validation fails
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters.');
        }

        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        if (!password_verify($oldPassword, $user['password_hash'])) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $this->db->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
            ->execute([':hash' => $newHash, ':id' => $userId]);

        // Invalidate all existing sessions for security
        $this->db->prepare('DELETE FROM sessions WHERE user_id = :user_id')
            ->execute([':user_id' => $userId]);

        return true;
    }

    /**
     * Create a password reset token for the given email.
     *
     * @param string $email
     * @return string The reset token (to be sent via email)
     * @throws RuntimeException If email not found
     */
    public function requestPasswordReset(string $email): string
    {
        $email = strtolower(trim($email));

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        // Invalidate existing reset tokens for this user
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
            ->execute([':user_id' => $user['id']]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (self::PASSWORD_RESET_LIFETIME * 60));

        $this->db->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, created_at)
             VALUES (:user_id, :token, :expires_at, NOW())'
        )->execute([
            ':user_id'    => $user['id'],
            ':token'      => hash('sha256', $token),
            ':expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Reset a user's password using a reset token.
     *
     * @param string $token       The reset token
     * @param string $newPassword The new password
     * @return bool
     * @throws RuntimeException On invalid/expired token
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $hashedToken = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'SELECT user_id FROM password_resets
             WHERE token = :token AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':token' => $hashedToken]);
        $reset = $stmt->fetch();

        if (!$reset) {
            throw new RuntimeException('Invalid or expired reset token.');
        }

        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $this->db->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id')
            ->execute([':hash' => $newHash, ':id' => $reset['user_id']]);

        // Delete all reset tokens for this user
        $this->db->prepare('DELETE FROM password_resets WHERE user_id = :user_id')
            ->execute([':user_id' => $reset['user_id']]);

        // Invalidate all existing sessions
        $this->db->prepare('DELETE FROM sessions WHERE user_id = :user_id')
            ->execute([':user_id' => $reset['user_id']]);

        return true;
    }

    /**
     * Extend a session's expiration.
     *
     * @param string $token The raw (unhashed) session token
     * @return array|null Updated user data, or null if session invalid
     */
    public function refreshSession(string $token): ?array
    {
        $hashedToken = hash('sha256', $token);
        $newExpiry = date('Y-m-d H:i:s', time() + ($this->sessionLifetime * 60));

        $stmt = $this->db->prepare(
            'UPDATE sessions SET expires_at = :expires_at
             WHERE token = :token AND expires_at > NOW()'
        );
        $stmt->execute([
            ':expires_at' => $newExpiry,
            ':token'      => $hashedToken,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->validateSession($token);
    }

    /**
     * Update user profile information.
     */
    public function updateProfile(int $userId, array $data): array
    {
        $fields = [];
        $params = [':id' => $userId];

        if (isset($data['first_name'])) {
            $fields[] = 'first_name = :first_name';
            $params[':first_name'] = trim($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $fields[] = 'last_name = :last_name';
            $params[':last_name'] = trim($data['last_name']);
        }
        if (isset($data['phone'])) {
            $fields[] = 'phone = :phone';
            $params[':phone'] = trim($data['phone']);
        }

        if (!empty($fields)) {
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';
            $this->db->prepare($sql)->execute($params);
        }

        return $this->getUserById($userId);
    }

    /**
     * Get user by ID (without password hash).
     */
    public function getUserById(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, first_name, last_name, email, phone, role, is_owner, is_active, created_at, updated_at, last_login_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $user['id'] = (int) $user['id'];
        $user['is_owner'] = (bool) $user['is_owner'];
        $user['is_active'] = (bool) $user['is_active'];

        return $user;
    }

    /**
     * Check if login is rate-limited for an email.
     */
    private function isLoginRateLimited(string $email): bool
    {
        $windowStart = date('Y-m-d H:i:s', time() - $this->loginWindow);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as attempts FROM login_attempts
             WHERE email = :email AND success = 0 AND created_at > :window_start'
        );
        $stmt->execute([
            ':email'        => $email,
            ':window_start' => $windowStart,
        ]);
        $result = $stmt->fetch();

        return ($result['attempts'] ?? 0) >= $this->maxLoginAttempts;
    }

    /**
     * Record a login attempt.
     */
    private function recordLoginAttempt(string $email, bool $success): void
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        $this->db->prepare(
            'INSERT INTO login_attempts (email, ip_address, success, created_at)
             VALUES (:email, :ip, :success, NOW())'
        )->execute([
            ':email'   => $email,
            ':ip'      => $ip,
            ':success' => $success ? 1 : 0,
        ]);

        // On successful login, clear previous failed attempts
        if ($success) {
            $this->db->prepare(
                'DELETE FROM login_attempts WHERE email = :email AND success = 0'
            )->execute([':email' => $email]);
        }
    }

    public function logoutAll(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function setup2fa(int $userId): array
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS temp_2fa_tokens (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_user  (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $totp   = new TotpService();
        $secret = $totp->generateSecret();

        $stmt = $this->db->prepare(
            'SELECT email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $this->db->prepare(
            'UPDATE users SET two_factor_secret = :secret, updated_at = NOW() WHERE id = :id'
        )->execute([':secret' => $secret, ':id' => $userId]);

        return [
            'secret' => $secret,
            'qr_uri' => $totp->generateQrCodeUri($secret, $user['email']),
        ];
    }

    public function enable2fa(int $userId, string $code): void
    {
        $stmt = $this->db->prepare(
            'SELECT two_factor_secret FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['two_factor_secret'])) {
            throw new RuntimeException('2FA setup not initiated.');
        }

        $totp = new TotpService();
        if (!$totp->verify($user['two_factor_secret'], $code)) {
            throw new RuntimeException('Invalid TOTP code.');
        }

        $this->db->prepare(
            'UPDATE users SET two_factor_enabled = 1, two_factor_confirmed_at = NOW(), updated_at = NOW() WHERE id = :id'
        )->execute([':id' => $userId]);
    }

    public function verify2fa(int $userId, string $code): bool
    {
        $stmt = $this->db->prepare(
            'SELECT two_factor_secret FROM users WHERE id = :id AND two_factor_enabled = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['two_factor_secret'])) {
            return false;
        }

        return (new TotpService())->verify($user['two_factor_secret'], $code);
    }

    public function disable2fa(int $userId, string $code): void
    {
        $stmt = $this->db->prepare(
            'SELECT two_factor_secret FROM users WHERE id = :id AND two_factor_enabled = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['two_factor_secret'])) {
            throw new RuntimeException('2FA is not enabled.');
        }

        if (!(new TotpService())->verify($user['two_factor_secret'], $code)) {
            throw new RuntimeException('Invalid TOTP code.');
        }

        $this->db->prepare(
            'UPDATE users
             SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_confirmed_at = NULL, updated_at = NOW()
             WHERE id = :id'
        )->execute([':id' => $userId]);
    }

    public function loginWith2fa(string $tempToken, string $totpCode): array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id FROM temp_2fa_tokens WHERE token = :token AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([':token' => $tempToken]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Invalid or expired 2FA token.');
        }

        $userId = (int) $row['user_id'];

        $this->db->prepare('DELETE FROM temp_2fa_tokens WHERE token = :token')
            ->execute([':token' => $tempToken]);

        if (!$this->verify2fa($userId, $totpCode)) {
            throw new RuntimeException('Invalid TOTP code.');
        }

        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->sessionLifetime * 60));

        $ip        = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $this->db->prepare(
            'INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at, created_at)
             VALUES (:user_id, :token, :ip, :user_agent, :expires_at, NOW())'
        )->execute([
            ':user_id'    => $userId,
            ':token'      => hash('sha256', $token),
            ':ip'         => $ip,
            ':user_agent' => substr($userAgent, 0, 512),
            ':expires_at' => $expiresAt,
        ]);

        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $userId]);

        return [
            'user'  => $this->getUserById($userId),
            'token' => $token,
        ];
    }

    public function verifyEmail(string $token): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM users WHERE email_verification_token = :token LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('Invalid or already-used verification token.');
        }

        $this->db->prepare(
            'UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, updated_at = NOW()
             WHERE id = :id'
        )->execute([':id' => $user['id']]);
    }

    public function sendEmailVerification(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $token = bin2hex(random_bytes(24));

        $this->db->prepare(
            'UPDATE users SET email_verification_token = :token, updated_at = NOW() WHERE id = :id'
        )->execute([':token' => $token, ':id' => $userId]);

        error_log(sprintf(
            '[CeylonCues] Email verification token for %s (user #%d): %s',
            $user['email'],
            $userId,
            $token,
        ));
    }

    /**
     * Validate registration data.
     *
     * @throws InvalidArgumentException
     */
    private function validateRegistration(array $data): void
    {
        $errors = [];

        if (empty($data['first_name']) || strlen(trim($data['first_name'])) < 2) {
            $errors['first_name'] = 'First name must be at least 2 characters.';
        }
        if (strlen(trim($data['first_name'] ?? '')) > 100) {
            $errors['first_name'] = 'First name must not exceed 100 characters.';
        }

        if (empty($data['last_name']) || strlen(trim($data['last_name'])) < 2) {
            $errors['last_name'] = 'Last name must be at least 2 characters.';
        }
        if (strlen(trim($data['last_name'] ?? '')) > 100) {
            $errors['last_name'] = 'Last name must not exceed 100 characters.';
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Password confirmation does not match.';
        }

        if (!empty($data['phone']) && !preg_match('/^\+?[\d\s\-()]{7,20}$/', $data['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors));
        }
    }
}
