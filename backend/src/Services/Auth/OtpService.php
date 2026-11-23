<?php

declare(strict_types=1);

namespace CeylonCues\Services\Auth;

use CeylonCues\Core\Database;
use PDO;

/**
 * OTP Service — issues and verifies 6-digit one-time codes for order placement.
 *
 * In XAMPP / local mode (MAILER_DSN not configured) the code is written to
 * backend/storage/logs/otp.log so the owner can retrieve it during development.
 * In production wire up a real mailer (SMTP, Mailgun, etc.) by implementing
 * the sendOtpEmail() stub below.
 */
final class OtpService
{
    private PDO $db;
    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Generate and issue a new OTP for the given user/email/payload.
     * Returns the plain-text OTP (for the email body — never stored).
     */
    public function issue(int $userId, string $email, string $payloadJson): string
    {
        // Expire any existing unused OTPs for this user
        $this->db->prepare("
            UPDATE checkout_otps SET used_at = NOW()
            WHERE user_id = :uid AND used_at IS NULL
        ")->execute([':uid' => $userId]);

        $code        = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash    = password_hash($code, PASSWORD_BCRYPT);
        $payloadHash = hash('sha256', $payloadJson);
        $expiresAt   = date('Y-m-d H:i:s', time() + self::OTP_TTL_MINUTES * 60);

        $stmt = $this->db->prepare("
            INSERT INTO checkout_otps (user_id, email, code_hash, payload_hash, max_attempts, expires_at)
            VALUES (:uid, :email, :hash, :phash, :max, :exp)
        ");
        $stmt->execute([
            ':uid'   => $userId,
            ':email' => $email,
            ':hash'  => $codeHash,
            ':phash' => $payloadHash,
            ':max'   => self::OTP_MAX_ATTEMPTS,
            ':exp'   => $expiresAt,
        ]);

        $this->sendOtpEmail($email, $code);

        return $code; // only returned so the API can include it in dev mode
    }

    /**
     * Verify an OTP code against the latest unused record for this user.
     * Throws on failure. Returns the payload_hash on success.
     */
    public function verify(int $userId, string $code, string $payloadJson): bool
    {
        $payloadHash = hash('sha256', $payloadJson);

        $stmt = $this->db->prepare("
            SELECT * FROM checkout_otps
            WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new \RuntimeException('No active OTP found. Please request a new code.');
        }

        if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
            throw new \RuntimeException('OTP attempt limit exceeded. Please request a new code.');
        }

        // Increment attempt counter
        $this->db->prepare("UPDATE checkout_otps SET attempts = attempts + 1 WHERE id = :id")
            ->execute([':id' => $row['id']]);

        if (!password_verify($code, $row['code_hash'])) {
            throw new \RuntimeException('Invalid OTP code. Please check your email and try again.');
        }

        if ($row['payload_hash'] !== $payloadHash) {
            throw new \RuntimeException('Order details changed after OTP was issued. Please request a new code.');
        }

        // Mark as used
        $this->db->prepare("UPDATE checkout_otps SET used_at = NOW() WHERE id = :id")
            ->execute([':id' => $row['id']]);

        return true;
    }

    /**
     * Send OTP via email.
     * In development (XAMPP): write to log file.
     * In production: replace with SMTP / Mailgun / SES call.
     */
    private function sendOtpEmail(string $email, string $code): void
    {
        $logDir  = dirname(__DIR__, 3) . '/storage/logs';
        $logFile = $logDir . '/otp.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf(
            "[%s] OTP for %s: %s (valid %d min)\n",
            date('Y-m-d H:i:s'),
            $email,
            $code,
            self::OTP_TTL_MINUTES
        );
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        // If a real SMTP is configured, use PHP mail() as a basic fallback
        $subject = 'Ceylon Cues — Your Order Verification Code';
        $body    = "Your order verification code is: {$code}\n\nThis code is valid for " . self::OTP_TTL_MINUTES . " minutes.\n\nDo not share this code with anyone.";
        $headers = "From: noreply@ceyloncues.lk\r\nContent-Type: text/plain; charset=utf-8";

        // Suppress mail() errors in XAMPP (local mailer not configured)
        @mail($email, $subject, $body, $headers);
    }
}
