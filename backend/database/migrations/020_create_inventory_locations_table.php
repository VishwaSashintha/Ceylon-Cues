<?php

declare(strict_types=1);

/**
 * Migration: Create inventory_locations table
 */

return [
    'up' => "
        CREATE TABLE `inventory_locations` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `type` ENUM('warehouse','shop','virtual') NOT NULL DEFAULT 'warehouse',
            `address` TEXT DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `is_default` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_locations_type` (`type`),
            INDEX `idx_locations_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `inventory_locations`",
];
