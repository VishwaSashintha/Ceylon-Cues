<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Background Notification Worker Daemon / Cron Runner
 *
 * Drains notification_queue independently from user browser sessions so
 * WhatsApp notifications are delivered even when no one is logged in.
 *
 * Usage:
 *   php bin/notification-worker.php             (Single run — use with cron)
 *   php bin/notification-worker.php --daemon    (Continuous 5-second poll loop)
 *
 * Windows Task Scheduler (recommended for localhost):
 *   Program : C:\xampp\php\php.exe
 *   Arguments: "C:\Users\Vishwa\Desktop\Ceylon Cues\backend\bin\notification-worker.php"
 *   Trigger  : Repeat every 5 minutes indefinitely
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use CeylonCues\Core\Env;
use CeylonCues\Services\Notifications\NotificationQueueService;

Env::load(dirname(__DIR__) . '/.env');

// ── Single-instance lock (prevents overlapping cron runs) ─────────────────
$lockDir = dirname(__DIR__) . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile   = $lockDir . '/notification-worker.lock';
$lockHandle = fopen($lockFile, 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] Notification worker already running. Exiting.' . PHP_EOL;
    exit(0);
}
// ──────────────────────────────────────────────────────────────────────────

$queueService = new NotificationQueueService();
$isDaemon     = in_array('--daemon', $argv, true);

echo '[' . date('Y-m-d H:i:s') . '] Starting Ceylon Cues Notification Worker...' . PHP_EOL;

do {
    $processed = $queueService->processPendingQueue(10);
    if ($processed > 0) {
        echo '[' . date('Y-m-d H:i:s') . "] Processed {$processed} notification(s)." . PHP_EOL;
    }

    if ($isDaemon) {
        sleep(5);
    }
} while ($isDaemon);

echo '[' . date('Y-m-d H:i:s') . '] Notification worker run complete.' . PHP_EOL;

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
