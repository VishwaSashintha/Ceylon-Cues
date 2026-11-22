<?php

/**
 * Ceylon Cues — Fresh Installation Script
 *
 * Creates the database if it doesn't exist and runs all migrations.
 * Does NOT create any user accounts (that's done by bootstrap).
 *
 * Usage:
 *   php database/install.php
 *
 * @package CeylonCues\Database
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

// ── Load .env ────────────────────────────────────────────────────────────────

$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "[ERROR] .env file not found at: {$envFile}\n");
    fwrite(STDERR, "Copy .env.example to .env and configure your database settings.\n");
    exit(1);
}

$envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
    }
}

// ── Configuration ────────────────────────────────────────────────────────────

$dbHost    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort    = $_ENV['DB_PORT'] ?? '3306';
$dbName    = $_ENV['DB_DATABASE'] ?? 'ceylon_cues';
$dbUser    = $_ENV['DB_USERNAME'] ?? 'root';
$dbPass    = $_ENV['DB_PASSWORD'] ?? '';
$dbCharset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
$dbCollate = $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci';

echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
echo "║       Ceylon Cues — Database Installation        ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── Step 1: Connect without database to create it ────────────────────────────

echo "  → Connecting to MySQL server at {$dbHost}:{$dbPort}...\n";

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset={$dbCharset}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    echo "  \033[32m✓\033[0m Connected successfully.\n\n";
} catch (PDOException $e) {
    fwrite(STDERR, "  \033[31m✗\033[0m Connection failed: {$e->getMessage()}\n");
    fwrite(STDERR, "\n  Please check your .env database configuration.\n\n");
    exit(1);
}

// ── Step 2: Create database if not exists ────────────────────────────────────

echo "  → Creating database '{$dbName}' if it doesn't exist...\n";

try {
    $pdo->exec(
        "CREATE DATABASE IF NOT EXISTS `{$dbName}` " .
        "CHARACTER SET {$dbCharset} COLLATE {$dbCollate}"
    );
    echo "  \033[32m✓\033[0m Database '{$dbName}' is ready.\n\n";
} catch (PDOException $e) {
    fwrite(STDERR, "  \033[31m✗\033[0m Failed to create database: {$e->getMessage()}\n\n");
    exit(1);
}

// Close the connection without database
$pdo = null;

// ── Step 3: Run all migrations ───────────────────────────────────────────────

echo "  → Running migrations...\n\n";

// Use the migrate.php script
$migrateScript = $projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrate.php';

if (!file_exists($migrateScript)) {
    fwrite(STDERR, "  \033[31m✗\033[0m Migration script not found at: {$migrateScript}\n\n");
    exit(1);
}

// Include and run - it will connect to the database itself
$exitCode = 0;
$phpBinary = PHP_BINARY ?: 'php';
passthru("\"{$phpBinary}\" \"{$migrateScript}\"", $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "\n  \033[31m✗\033[0m Migration failed with exit code {$exitCode}.\n\n");
    exit(1);
}

// ── Done ─────────────────────────────────────────────────────────────────────

echo "\n";
echo "╔══════════════════════════════════════════════════╗\n";
echo "║         Installation Complete!                   ║\n";
echo "╠══════════════════════════════════════════════════╣\n";
echo "║                                                  ║\n";
echo "║  Database: {$dbName}" . str_repeat(' ', max(0, 36 - strlen($dbName))) . "║\n";
echo "║                                                  ║\n";
echo "║  Next steps:                                     ║\n";
echo "║  1. Run: php bootstrap/init.php                  ║\n";
echo "║     (creates owner account)                      ║\n";
echo "║  2. Run: php database/seed.php                   ║\n";
echo "║     (loads sample data - optional)               ║\n";
echo "║                                                  ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

exit(0);
