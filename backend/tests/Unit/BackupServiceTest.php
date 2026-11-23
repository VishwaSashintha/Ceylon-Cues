<?php

declare(strict_types=1);

namespace CeylonCues\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cc_backup_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $appKey = 'test-app-key-32-bytes-padded!!';
        $key = hash('sha256', $appKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $plaintext = "-- SQL DUMP\nINSERT INTO test VALUES (1);\n";

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        $this->assertIsString($ciphertext);
        $this->assertSame(16, strlen($tag));

        $stored = $iv . $tag . $ciphertext;

        $decodedIv = substr($stored, 0, 12);
        $decodedTag = substr($stored, 12, 16);
        $decodedCipher = substr($stored, 28);
        $decrypted = openssl_decrypt($decodedCipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $decodedIv, $decodedTag);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $appKey = 'test-app-key-32-bytes-padded!!';
        $key = hash('sha256', $appKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $plaintext = "-- SENSITIVE DATA\n";

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        // Tamper with the ciphertext
        $tamperedCipher = substr_replace($ciphertext, chr(ord($ciphertext[0]) ^ 0xFF), 0, 1);

        $decrypted = openssl_decrypt($tamperedCipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        $this->assertFalse($decrypted);
    }

    public function testPurgeOldBackupsDeletesExpiredFiles(): void
    {
        $oldFile = $this->tmpDir . '/backup_2020-01-01.sql.enc';
        file_put_contents($oldFile, 'old');
        touch($oldFile, strtotime('-31 days'));

        $newFile = $this->tmpDir . '/backup_today.sql.enc';
        file_put_contents($newFile, 'new');

        $keepDays = 30;
        $cutoff = time() - ($keepDays * 86400);
        $deleted = 0;
        foreach (glob($this->tmpDir . '/*.sql.enc') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }
}
