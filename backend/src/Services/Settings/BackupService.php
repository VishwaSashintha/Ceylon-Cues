<?php

declare(strict_types=1);

namespace CeylonCues\Services\Settings;

use CeylonCues\Core\Database;
use PDO;
use RuntimeException;

final class BackupService
{
    private string $backupDir;

    public function __construct()
    {
        $this->backupDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    public function createBackup(): array
    {
        $db = Database::getInstance()->getConnection();
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sqlContent = "-- Ceylon Cues Database Backup\n";
        $sqlContent .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sqlContent .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createSql = $db->query("SHOW CREATE TABLE `{$table}`")->fetchColumn(1);
            $sqlContent .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sqlContent .= "{$createSql};\n\n";

            $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cols = array_map(fn($col) => "`{$col}`", array_keys($row));
                $vals = array_map(function ($val) use ($db) {
                    return $val === null ? 'NULL' : $db->quote((string)$val);
                }, array_values($row));

                $sqlContent .= sprintf(
                    "INSERT INTO `%s` (%s) VALUES (%s);\n",
                    $table,
                    implode(', ', $cols),
                    implode(', ', $vals)
                );
            }
            $sqlContent .= "\n";
        }

        $sqlContent .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $key = hash('sha256', $_ENV['APP_KEY'] ?? 'changeme', true);
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($sqlContent, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($encrypted === false) {
            throw new RuntimeException('Failed to encrypt backup.');
        }

        $filename = 'ceylon_cues_backup_' . date('Y-m-d_His') . '.sql.enc';
        $filepath = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        $written = file_put_contents($filepath, $iv . $tag . $encrypted);
        if ($written === false) {
            throw new RuntimeException("Could not write backup file: {$filepath}");
        }

        return [
            'filename'   => $filename,
            'file_path'  => $filepath,
            'file_size'  => $written,
            'tables'     => count($tables),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function restoreBackup(string $filename): void
    {
        $filepath = $this->backupDir . DIRECTORY_SEPARATOR . basename($filename);

        if (!file_exists($filepath)) {
            throw new RuntimeException("Backup file not found: {$filename}");
        }

        $data = file_get_contents($filepath);
        if ($data === false || strlen($data) <= 28) {
            throw new RuntimeException('Backup file is empty or corrupt.');
        }

        $key = hash('sha256', $_ENV['APP_KEY'] ?? 'changeme', true);
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($decrypted === false) {
            throw new RuntimeException('Backup file integrity verification failed: corrupted or tampered ciphertext.');
        }

        $db = Database::getInstance()->getConnection();
        $db->exec("SET FOREIGN_KEY_CHECKS=0;");

        $statements = array_filter(
            array_map('trim', explode(";\n", $decrypted)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );

        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $db->exec($statement);
            }
        }

        $db->exec("SET FOREIGN_KEY_CHECKS=1;");
    }

    public function purgeOldBackups(int $keepDays = 30): int
    {
        $cutoff = time() - ($keepDays * 86400);
        $deleted = 0;

        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . '*.sql.enc') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
