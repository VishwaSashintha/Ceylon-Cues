<?php

declare(strict_types=1);

/**
 * Migration: Create refunds table
 */

return [
    'up' => "
        CREATE TABLE `refunds` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `payment_id` BIGINT UNSIGNED NOT NULL,
            `refund_amount` DECIMAL(14,2) NOT NULL,
            `reason` TEXT NOT NULL,
            `status` ENUM('pending','approved','processing','completed','rejected') NOT NULL DEFAULT 'pending',
            `refund_method` VARCHAR(50) NOT NULL,
            `processed_by` BIGINT UNSIGNED DEFAULT NULL,
            `processed_at` TIMESTAMP NULL DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_refunds_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `refunds`",
];
