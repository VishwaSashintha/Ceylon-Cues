<?php

declare(strict_types=1);

/**
 * Migration: Create stock_counts table
 */

return [
    'up' => "
        CREATE TABLE `stock_counts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
            `counted_by` BIGINT UNSIGNED NOT NULL,
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_counts`",
];
