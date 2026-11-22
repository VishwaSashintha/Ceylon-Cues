<?php

declare(strict_types=1);

/**
 * Migration: Create support_messages table
 */

return [
    'up' => "
        CREATE TABLE `support_messages` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `sender_name` VARCHAR(100) NOT NULL,
            `message` TEXT NOT NULL,
            `is_internal` BOOLEAN NOT NULL DEFAULT 0,
            `attachments` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `support_messages`",
];
