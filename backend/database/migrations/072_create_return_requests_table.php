<?php

declare(strict_types=1);

/**
 * Migration: Create return_requests table
 */

return [
    'up' => "
        CREATE TABLE `return_requests` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `reason` TEXT NOT NULL,
            `status` ENUM('requested','approved','received','refunded','rejected','cancelled') NOT NULL DEFAULT 'requested',
            `processed_by` BIGINT UNSIGNED DEFAULT NULL,
            `rejection_reason` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_returns_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `return_requests`",
];
