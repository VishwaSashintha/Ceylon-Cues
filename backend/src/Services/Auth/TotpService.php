<?php

declare(strict_types=1);

namespace CeylonCues\Services\Auth;

use RuntimeException;

final class TotpService
{
    private const DIGITS   = 6;
    private const PERIOD   = 30;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $secret = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $secret .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $secret .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $secret;
    }

    public function generateTotp(string $secret, int $offset = 0): string
    {
        $counter = (int) floor(time() / self::PERIOD) + $offset;
        $key = $this->base32Decode($secret);

        $time = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $time, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            (ord($hash[$offset + 3])  & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code): bool
    {
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->generateTotp($secret, $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function generateQrCodeUri(string $secret, string $email, string $issuer = 'CeylonCues'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    private function base32Decode(string $input): string
    {
        $input = strtoupper($input);
        $map   = array_flip(str_split(self::ALPHABET));
        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($input) as $char) {
            if ($char === '=') {
                break;
            }
            if (!isset($map[$char])) {
                throw new RuntimeException('Invalid base32 character: ' . $char);
            }
            $buffer = ($buffer << 5) | $map[$char];
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }
}
