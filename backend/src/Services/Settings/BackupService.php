<?php

declare(strict_types=1);

namespace CeylonCues\Services\Settings;

use CeylonCues\Core\Database;
use RuntimeException;

/**
 * Database Backup & Recovery Service
 */
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

    /**
     * Create a SQL database dump.
     */
    public function createBackup(): array
    {
        $db = Database::getInstance()->getConnection();
        $tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

        $filename = 'ceylon_cues_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            throw new RuntimeException("Could not open backup file for writing: {$filepath}");
        }

        fwrite($handle, "-- Ceylon Cues Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            // Table structure
            $createSql = $db->query("SHOW CREATE TABLE `{$table}`")->fetchColumn(1);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, "{$createSql};\n\n");

            // Table rows
            $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $cols = array_map(fn($col) => "`{$col}`", array_keys($row));
                    $vals = array_map(function ($val) use ($db) {
                        return $val === null ? 'NULL' : $db->quote((string)$val);
                    }, array_values($row));

                    $insertSql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES (%s);\n",
                        $table,
                        implode(', ', $cols),
                        implode(', ', $vals)
                    );
                    fwrite($handle, $insertSql);
                }
                fwrite($handle, "\n");
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return [
            'filename'  => $filename,
            'file_path' => $filepath,
            'file_size' => filesize($filepath),
            'tables'    => count($tables),
            'created_at'=> date('Y-m-d H:i:s'),
        ];
    }
}
