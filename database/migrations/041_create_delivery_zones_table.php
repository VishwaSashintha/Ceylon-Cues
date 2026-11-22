<?php

declare(strict_types=1);

/**
 * Migration: Create delivery_zones table
 */

return [
    'up' => "
        CREATE TABLE `delivery_zones` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `districts` JSON NOT NULL,
            `delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `free_delivery_threshold` DECIMAL(14,2) DEFAULT NULL,
            `estimated_days` INT DEFAULT 3,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_zones_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `delivery_zones`",
];
