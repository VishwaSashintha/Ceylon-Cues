<?php

declare(strict_types=1);

/**
 * Migration: Create notification_queue table
 * Independent background queue for reliable notifications.
 */

return [
    'up' => "
        CREATE TABLE `notification_queue` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `channel` ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp',
            `recipient` VARCHAR(255) NOT NULL,
            `template_id` BIGINT UNSIGNED DEFAULT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `payload` JSON NOT NULL,
            `status` ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
            `priority` INT NOT NULL DEFAULT 5,
            `attempts` INT NOT NULL DEFAULT 0,
            `max_attempts` INT NOT NULL DEFAULT 3,
            `provider_message_id` VARCHAR(255) DEFAULT NULL,
            `provider_response` JSON DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `scheduled_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at` TIMESTAMP NULL DEFAULT NULL,
            `next_retry_at` TIMESTAMP NULL DEFAULT NULL,
            `idempotency_key` VARCHAR(255) UNIQUE DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`) ON DELETE SET NULL,
            INDEX `idx_queue_process` (`status`, `priority`, `scheduled_at`, `next_retry_at`),
            INDEX `idx_queue_event` (`event_type`),
            INDEX `idx_queue_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `notification_queue`",
];
