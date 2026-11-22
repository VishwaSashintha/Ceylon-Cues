<?php

/**
 * Ceylon Cues — Database Migration Runner
 *
 * Usage:
 *   php database/migrate.php              Run pending migrations
 *   php database/migrate.php --rollback   Rollback last batch
 *   php database/migrate.php --fresh      Drop all tables, re-run everything
 *   php database/migrate.php --status     Show migration status
 *
 * @package CeylonCues\Database
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$projectRoot = dirname(__DIR__);

// Load .env
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "[ERROR] .env file not found at: {$envFile}\n");
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

// ── Database Connection ──────────────────────────────────────────────────────

$dbHost     = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort     = $_ENV['DB_PORT'] ?? '3306';
$dbName     = $_ENV['DB_DATABASE'] ?? 'ceylon_cues';
$dbUser     = $_ENV['DB_USERNAME'] ?? 'root';
$dbPass     = $_ENV['DB_PASSWORD'] ?? '';
$dbCharset  = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[ERROR] Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

// ── CLI Arguments ────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);
$command = $args[0] ?? '--run';

// ── Migrations Table ─────────────────────────────────────────────────────────

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `batch` INT UNSIGNED NOT NULL,
            `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function getCompletedMigrations(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT migration, batch FROM migrations ORDER BY id ASC");
    return $stmt->fetchAll();
}

function getLastBatch(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT MAX(batch) AS max_batch FROM migrations");
    $row  = $stmt->fetch();
    return (int) ($row['max_batch'] ?? 0);
}

function getMigrationFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
    sort($files); // Sort alphabetically (001_, 002_, etc.)
    return $files;
}

function recordMigration(PDO $pdo, string $name, int $batch): void
{
    $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)");
    $stmt->execute([':migration' => $name, ':batch' => $batch]);
}

function removeMigrationRecord(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare("DELETE FROM migrations WHERE migration = :migration");
    $stmt->execute([':migration' => $name]);
}

function output(string $message, string $type = 'info'): void
{
    $prefix = match ($type) {
        'success' => "\033[32m✓\033[0m",
        'error'   => "\033[31m✗\033[0m",
        'warning' => "\033[33m!\033[0m",
        'info'    => "\033[36m→\033[0m",
        default   => " ",
    };
    echo "  {$prefix} {$message}\n";
}

// ── Commands ─────────────────────────────────────────────────────────────────

$migrationsDir = $projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

switch ($command) {
    case '--run':
    case 'run':
        runMigrations($pdo, $migrationsDir);
        break;

    case '--rollback':
    case 'rollback':
        rollbackMigrations($pdo, $migrationsDir);
        break;

    case '--fresh':
    case 'fresh':
        freshMigrations($pdo, $migrationsDir, $dbName);
        break;

    case '--status':
    case 'status':
        showStatus($pdo, $migrationsDir);
        break;

    default:
        echo "\nCeylon Cues — Migration Runner\n";
        echo "──────────────────────────────\n";
        echo "Usage:\n";
        echo "  php database/migrate.php              Run pending migrations\n";
        echo "  php database/migrate.php --rollback   Rollback last batch\n";
        echo "  php database/migrate.php --fresh      Drop all & re-run\n";
        echo "  php database/migrate.php --status     Show migration status\n\n";
        exit(0);
}

// ── Run Pending Migrations ───────────────────────────────────────────────────

function runMigrations(PDO $pdo, string $dir): void
{
    echo "\n\033[1mCeylon Cues — Running Migrations\033[0m\n";
    echo str_repeat('─', 50) . "\n\n";

    ensureMigrationsTable($pdo);

    $completed  = array_column(getCompletedMigrations($pdo), 'migration');
    $files      = getMigrationFiles($dir);
    $batch      = getLastBatch($pdo) + 1;
    $ranCount   = 0;

    foreach ($files as $filePath) {
        $fileName = basename($filePath, '.php');

        if (in_array($fileName, $completed, true)) {
            continue;
        }

        $migration = require $filePath;

        if (!is_array($migration) || !isset($migration['up'])) {
            output("Skipping {$fileName}: invalid format", 'warning');
            continue;
        }

        try {
            // Support multi-statement migrations (separated by semicolons)
            $upStatements = array_filter(
                array_map('trim', explode(';', $migration['up'])),
                fn(string $s) => $s !== ''
            );

            foreach ($upStatements as $sql) {
                $pdo->exec($sql);
            }

            recordMigration($pdo, $fileName, $batch);
            output("Migrated: {$fileName}", 'success');
            $ranCount++;
        } catch (PDOException $e) {
            output("Failed: {$fileName} — {$e->getMessage()}", 'error');
            exit(1);
        }
    }

    if ($ranCount === 0) {
        output("Nothing to migrate. All migrations are up to date.", 'info');
    } else {
        echo "\n";
        output("Ran {$ranCount} migration(s) in batch {$batch}.", 'success');
    }

    echo "\n";
}

// ── Rollback Last Batch ──────────────────────────────────────────────────────

function rollbackMigrations(PDO $pdo, string $dir): void
{
    echo "\n\033[1mCeylon Cues — Rolling Back Last Batch\033[0m\n";
    echo str_repeat('─', 50) . "\n\n";

    ensureMigrationsTable($pdo);

    $lastBatch = getLastBatch($pdo);

    if ($lastBatch === 0) {
        output("Nothing to rollback.", 'info');
        echo "\n";
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC"
    );
    $stmt->execute([':batch' => $lastBatch]);
    $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $rolledBack = 0;

    foreach ($migrations as $migrationName) {
        $filePath = $dir . DIRECTORY_SEPARATOR . $migrationName . '.php';

        if (!file_exists($filePath)) {
            output("File not found: {$migrationName}", 'warning');
            continue;
        }

        $migration = require $filePath;

        if (!is_array($migration) || !isset($migration['down'])) {
            output("No 'down' migration for: {$migrationName}", 'warning');
            continue;
        }

        try {
            $downStatements = array_filter(
                array_map('trim', explode(';', $migration['down'])),
                fn(string $s) => $s !== ''
            );

            foreach ($downStatements as $sql) {
                $pdo->exec($sql);
            }

            removeMigrationRecord($pdo, $migrationName);
            output("Rolled back: {$migrationName}", 'success');
            $rolledBack++;
        } catch (PDOException $e) {
            output("Rollback failed: {$migrationName} — {$e->getMessage()}", 'error');
            exit(1);
        }
    }

    output("Rolled back {$rolledBack} migration(s) from batch {$lastBatch}.", 'success');
    echo "\n";
}

// ── Fresh (Drop All & Re-run) ────────────────────────────────────────────────

function freshMigrations(PDO $pdo, string $dir, string $dbName): void
{
    echo "\n\033[1mCeylon Cues — Fresh Migration (Drop All & Re-run)\033[0m\n";
    echo str_repeat('─', 50) . "\n\n";

    // Disable FK checks to allow dropping in any order
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $stmt   = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        output("Dropped table: {$table}", 'warning');
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n";
    output("All tables dropped. Running migrations...", 'info');
    echo "\n";

    runMigrations($pdo, $dir);
}

// ── Status ───────────────────────────────────────────────────────────────────

function showStatus(PDO $pdo, string $dir): void
{
    echo "\n\033[1mCeylon Cues — Migration Status\033[0m\n";
    echo str_repeat('─', 60) . "\n\n";

    ensureMigrationsTable($pdo);

    $completed = array_column(getCompletedMigrations($pdo), 'batch', 'migration');
    $files     = getMigrationFiles($dir);

    printf("  %-50s %-10s\n", 'Migration', 'Status');
    echo '  ' . str_repeat('─', 60) . "\n";

    foreach ($files as $filePath) {
        $name = basename($filePath, '.php');

        if (isset($completed[$name])) {
            $status = "\033[32mRan (batch {$completed[$name]})\033[0m";
        } else {
            $status = "\033[33mPending\033[0m";
        }

        printf("  %-50s %s\n", $name, $status);
    }

    echo "\n";
}
