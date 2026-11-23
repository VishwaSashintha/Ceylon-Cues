<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Colombo');

$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

use CeylonCues\Core\Env;
use CeylonCues\Core\Database;
use CeylonCues\Services\Settings\BackupService;

Env::load($basePath . '/.env');

$lockDir = $basePath . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/cron.lock';
$lockHandle = fopen($lockFile, 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] [cron_lock] Another cron execution is currently running. Exiting to prevent overlap.' . "\n";
    exit(0);
}

function cronLog(string $job, string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [' . $job . '] ' . $message . "\n";
}

function tableExists(PDO $db, string $table): bool
{
    $quoted = $db->quote($table);
    $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && $stmt->fetchColumn() !== false;
}

$db = Database::getInstance()->getConnection();

// -------------------------------------------------------------------------
// Job A: Reservation Expiry (Releasing unpaid orders > expires_at)
// -------------------------------------------------------------------------
try {
    $expired = $db->query("
        SELECT id FROM orders
        WHERE status = 'pending'
          AND payment_status = 'unpaid'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
    ")->fetchAll(PDO::FETCH_COLUMN);

    $cancelledCount = 0;

    foreach ($expired as $orderId) {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :id")
               ->execute([':id' => $orderId]);

            $items = $db->prepare("SELECT product_id, variant_id, quantity, location_id FROM order_items WHERE order_id = :oid");
            $items->execute([':oid' => $orderId]);

            foreach ($items->fetchAll() as $item) {
                $locationId = !empty($item['location_id']) ? (int)$item['location_id'] : 1;

                $db->prepare("
                    UPDATE inventory_stock
                    SET reserved_quantity = GREATEST(0, reserved_quantity - :qty), updated_at = NOW()
                    WHERE product_id = :pid AND location_id = :lid
                ")->execute([
                    ':qty' => (int)$item['quantity'],
                    ':pid' => (int)$item['product_id'],
                    ':lid' => $locationId,
                ]);

                $db->prepare("
                    INSERT INTO stock_movements
                        (product_id, variant_id, location_id, movement_type, quantity, reference_type, reference_id, notes, created_at)
                    VALUES
                        (:pid, :vid, :lid, 'reservation_release', :qty, 'order', :ref, 'Reservation expired - auto-cancelled', NOW())
                ")->execute([
                    ':pid' => (int)$item['product_id'],
                    ':vid' => $item['variant_id'] ?? null,
                    ':lid' => $locationId,
                    ':qty' => (int)$item['quantity'],
                    ':ref' => $orderId,
                ]);
            }

            $db->commit();
            $cancelledCount++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    cronLog('reservation_expiry', "Done: {$cancelledCount} order(s) cancelled and reservations released.");
} catch (Throwable $e) {
    cronLog('reservation_expiry', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job B: Campaign Lifecycle
// -------------------------------------------------------------------------
try {
    if (tableExists($db, 'campaigns')) {
        $activated = $db->exec("
            UPDATE campaigns
            SET is_active = 1, updated_at = NOW()
            WHERE start_date <= NOW() AND end_date > NOW() AND is_active = 0
        ");

        $ended = $db->exec("
            UPDATE campaigns
            SET is_active = 0, updated_at = NOW()
            WHERE end_date <= NOW() AND is_active = 1
        ");

        cronLog('campaign_lifecycle', "Done: {$activated} activated, {$ended} ended.");
    } else {
        cronLog('campaign_lifecycle', 'Skipped: campaigns table does not exist.');
    }
} catch (Throwable $e) {
    cronLog('campaign_lifecycle', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job C: Coupon Expiry
// -------------------------------------------------------------------------
try {
    if (tableExists($db, 'discounts')) {
        $expired = $db->exec("
            UPDATE discounts
            SET is_active = 0, updated_at = NOW()
            WHERE is_active = 1 AND end_date IS NOT NULL AND end_date < NOW()
        ");

        cronLog('coupon_expiry', "Done: {$expired} discount code(s) expired.");
    } else {
        cronLog('coupon_expiry', 'Skipped: discounts table does not exist.');
    }
} catch (Throwable $e) {
    cronLog('coupon_expiry', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job D: Daily Sales Summary
// -------------------------------------------------------------------------
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS daily_sales_summary (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            summary_date  DATE         NOT NULL,
            total_orders  INT UNSIGNED NOT NULL DEFAULT 0,
            total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_summary_date (summary_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total_orders, COALESCE(SUM(total_amount), 0) AS total_revenue
        FROM orders
        WHERE DATE(created_at) = :today
          AND status IN ('confirmed', 'processing', 'shipped', 'delivered')
    ");
    $stmt->execute([':today' => $today]);
    $row = $stmt->fetch();

    $totalOrders  = (int)($row['total_orders'] ?? 0);
    $totalRevenue = (float)($row['total_revenue'] ?? 0.00);

    $db->prepare("
        INSERT INTO daily_sales_summary (summary_date, total_orders, total_revenue)
        VALUES (:date, :orders, :revenue)
        ON DUPLICATE KEY UPDATE
            total_orders  = VALUES(total_orders),
            total_revenue = VALUES(total_revenue),
            updated_at    = NOW()
    ")->execute([
        ':date'    => $today,
        ':orders'  => $totalOrders,
        ':revenue' => $totalRevenue,
    ]);

    cronLog('daily_sales_summary', "Done: {$totalOrders} orders, LKR {$totalRevenue} revenue for {$today}.");
} catch (Throwable $e) {
    cronLog('daily_sales_summary', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job E: Low Stock Alerts
// -------------------------------------------------------------------------
try {
    if (!tableExists($db, 'notification_queue')) {
        cronLog('low_stock_alerts', 'Skipped: notification_queue table does not exist.');
    } else {
        $lowStock = $db->query("
            SELECT p.id, p.name, s.quantity, s.reorder_level
            FROM inventory_stock s
            JOIN products p ON p.id = s.product_id
            WHERE s.quantity <= s.reorder_level
              AND s.reorder_level > 0
              AND p.is_active = 1
        ")->fetchAll();

        $alertCount = 0;
        foreach ($lowStock as $item) {
            $db->prepare("
                INSERT INTO notification_queue (channel, recipient, event_type, payload, status, created_at)
                VALUES ('email', 'owner@ceyloncues.lk', 'low_stock', :payload, 'pending', NOW())
            ")->execute([
                ':payload' => json_encode([
                    'product_id'   => (int)$item['id'],
                    'product_name' => $item['name'],
                    'quantity'     => (int)$item['quantity'],
                    'reorder_level'=> (int)$item['reorder_level'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $alertCount++;
        }

        cronLog('low_stock_alerts', "Done: {$alertCount} low-stock alert(s) queued.");
    }
} catch (Throwable $e) {
    cronLog('low_stock_alerts', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job F: Database Backup
// -------------------------------------------------------------------------
try {
    $backup = new BackupService();
    $result = $backup->createBackup();
    $purged = $backup->purgeOldBackups(30);
    cronLog('database_backup', "Done: backup {$result['filename']} created ({$result['tables']} tables). {$purged} old backup(s) purged.");
} catch (Throwable $e) {
    cronLog('database_backup', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job G: Audit Log Archive (never delete — archive to compressed JSONL)
// -------------------------------------------------------------------------
try {
    if (!tableExists($db, 'audit_logs')) {
        cronLog('audit_log_archive', 'Skipped: audit_logs table does not exist.');
    } else {
        $archiveDir = $basePath . '/storage/archives';
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0750, true);
        }

        // Archive logs older than 90 days, month by month
        $months = $db->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym
            FROM audit_logs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY ym
            ORDER BY ym ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $totalArchived = 0;
        foreach ($months as $ym) {
            $archiveFile = $archiveDir . '/audit_' . $ym . '.jsonl.gz';
            $start = $ym . '-01 00:00:00';
            $end   = date('Y-m-t 23:59:59', strtotime($start));

            $rows = $db->prepare("SELECT * FROM audit_logs WHERE created_at BETWEEN :s AND :e ORDER BY id ASC");
            $rows->execute([':s' => $start, ':e' => $end]);
            $logs = $rows->fetchAll(PDO::FETCH_ASSOC);

            if (empty($logs)) {
                continue;
            }

            $gz = gzopen($archiveFile, 'ab');
            if ($gz === false) {
                cronLog('audit_log_archive', "WARNING: Cannot open {$archiveFile} for writing.");
                continue;
            }
            foreach ($logs as $log) {
                gzwrite($gz, json_encode($log, JSON_UNESCAPED_UNICODE) . "\n");
            }
            gzclose($gz);

            // Remove archived rows from live table
            $db->prepare("DELETE FROM audit_logs WHERE created_at BETWEEN :s AND :e")
               ->execute([':s' => $start, ':e' => $end]);

            $totalArchived += count($logs);
        }

        cronLog('audit_log_archive', "Done: {$totalArchived} log(s) archived to storage/archives/.");
    }
} catch (Throwable $e) {
    cronLog('audit_log_archive', 'ERROR: ' . $e->getMessage());
}

// -------------------------------------------------------------------------
// Job H: Abandoned Cart Tracking
// -------------------------------------------------------------------------
try {
    if (!tableExists($db, 'carts') || !tableExists($db, 'notification_queue')) {
        cronLog('abandoned_cart', 'Skipped: required tables do not exist.');
    } else {
        // Mark carts older than 24 hours with items as abandoned and queue email
        $abandoned = $db->query("
            SELECT DISTINCT c.user_id, c.session_id, u.email
            FROM carts c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND c.user_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM notification_queue nq
                  WHERE nq.event_type = 'abandoned_cart'
                    AND JSON_UNQUOTE(JSON_EXTRACT(nq.payload, '$.user_id')) = CAST(c.user_id AS CHAR)
                    AND nq.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              )
        ")->fetchAll();

        $queued = 0;
        foreach ($abandoned as $row) {
            if (empty($row['email'])) {
                continue;
            }
            $db->prepare("
                INSERT INTO notification_queue (channel, recipient, event_type, payload, status, created_at)
                VALUES ('email', :email, 'abandoned_cart', :payload, 'pending', NOW())
            ")->execute([
                ':email'   => $row['email'],
                ':payload' => json_encode(['user_id' => $row['user_id']], JSON_UNESCAPED_UNICODE),
            ]);
            $queued++;
        }
        cronLog('abandoned_cart', "Done: {$queued} abandoned cart notification(s) queued.");
    }
} catch (Throwable $e) {
    cronLog('abandoned_cart', 'ERROR: ' . $e->getMessage());
}

// Release cron lock and exit cleanly
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
