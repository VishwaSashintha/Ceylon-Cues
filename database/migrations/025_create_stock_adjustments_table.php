<?php

declare(strict_types=1);

/**
 * Migration: Create stock_adjustments table
 */

return [
    'up' => "
        CREATE TABLE `stock_adjustments` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `reason` ENUM('damaged','lost','found','correction','expired','other') NOT NULL,
            `status` ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
            `notes` TEXT DEFAULT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `approved_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_adjustments_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_adjustments`",
];
