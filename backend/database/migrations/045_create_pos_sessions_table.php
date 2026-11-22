<?php

declare(strict_types=1);

/**
 * Migration: Create pos_sessions table
 */

return [
    'up' => "
        CREATE TABLE `pos_sessions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `cashier_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `closed_at` TIMESTAMP NULL DEFAULT NULL,
            `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `closing_balance` DECIMAL(14,2) DEFAULT NULL,
            `expected_balance` DECIMAL(14,2) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            INDEX `idx_pos_cashier` (`cashier_id`),
            INDEX `idx_pos_location` (`location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `pos_sessions`",
];
