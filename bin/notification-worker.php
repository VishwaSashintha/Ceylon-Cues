<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Background Notification Worker Daemon / Cron Runner
 *
 * This background worker is designed for continuous production operation.
 * It drains the notification_queue independently from user browser sessions,
 * allowing WhatsApp notifications to be delivered even when no one is logged into the ERP.
 *
 * Usage:
 *   php bin/notification-worker.php             (Single run, perfect for cron)
 *   php bin/notification-worker.php --daemon   (Continuous polling loop)
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

$queueService = new NotificationQueueService();
$isDaemon = in_array('--daemon', $argv, true);

echo "[" . date('Y-m-d H:i:s') . "] Starting Ceylon Cues Notification Worker...\n";

do {
    $processed = $queueService->processPendingQueue(10);
    if ($processed > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Processed {$processed} notification(s).\n";
    }

    if ($isDaemon) {
        sleep(5); // Poll every 5 seconds in daemon mode
    }
} while ($isDaemon);

echo "[" . date('Y-m-d H:i:s') . "] Notification worker run complete.\n";
