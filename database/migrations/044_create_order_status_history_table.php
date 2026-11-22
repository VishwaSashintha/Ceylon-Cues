<?php

declare(strict_types=1);

/**
 * Migration: Create order_status_history table
 */

return [
    'up' => "
        CREATE TABLE `order_status_history` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `old_status` VARCHAR(50) DEFAULT NULL,
            `new_status` VARCHAR(50) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `changed_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_osh_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `order_status_history`",
];
