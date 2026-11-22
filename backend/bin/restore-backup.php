#!/usr/bin/env php
<?php

declare(strict_types=1);

$backendDir = dirname(__DIR__);
require $backendDir . '/vendor/autoload.php';

$envFile = $backendDir . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Colombo');

if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php restore-backup.php <filename>\n");
    exit(1);
}

$filename = $argv[1];

try {
    $service = new \CeylonCues\Services\Settings\BackupService();
    $service->restoreBackup($filename);
    echo "Restore complete: {$filename}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Restore failed: " . $e->getMessage() . "\n");
    exit(1);
}
