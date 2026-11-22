<?php

declare(strict_types=1);

/**
 * Migration: Create notification_logs table
 */

return [
    'up' => "
        CREATE TABLE `notification_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `queue_id` BIGINT UNSIGNED DEFAULT NULL,
            `channel` VARCHAR(50) NOT NULL,
            `recipient` VARCHAR(255) NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `status` VARCHAR(50) NOT NULL,
            `provider_message_id` VARCHAR(255) DEFAULT NULL,
            `delivery_status` VARCHAR(50) DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`queue_id`) REFERENCES `notification_queue` (`id`) ON DELETE SET NULL,
            INDEX `idx_notif_log_created` (`created_at`),
            INDEX `idx_notif_log_event` (`event_type`, `channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `notification_logs`",
];
