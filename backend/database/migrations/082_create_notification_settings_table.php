<?php

declare(strict_types=1);

/**
 * Migration: Create notification_settings table
 */

return [
    'up' => "
        CREATE TABLE `notification_settings` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `whatsapp_enabled` BOOLEAN NOT NULL DEFAULT 1,
            `email_enabled` BOOLEAN NOT NULL DEFAULT 0,
            `sms_enabled` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_event_setting` (`user_id`, `event_type`),
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `notification_settings`",
];
