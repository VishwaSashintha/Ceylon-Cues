<?php

declare(strict_types=1);

/**
 * Migration: Create stock_transfers table
 */

return [
    'up' => "
        CREATE TABLE `stock_transfers` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `from_location_id` BIGINT UNSIGNED NOT NULL,
            `to_location_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('draft','pending','approved','in_transit','completed','cancelled') NOT NULL DEFAULT 'draft',
            `notes` TEXT DEFAULT NULL,
            `requested_by` BIGINT UNSIGNED NOT NULL,
            `approved_by` BIGINT UNSIGNED DEFAULT NULL,
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`from_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`to_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_transfers_status` (`status`),
            INDEX `idx_transfers_locations` (`from_location_id`, `to_location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_transfers`",
];
