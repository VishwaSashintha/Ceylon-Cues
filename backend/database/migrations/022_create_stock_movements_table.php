<?php

declare(strict_types=1);

/**
 * Migration: Create stock_movements table
 * Permanent inventory ledger tracking every stock change.
 */

return [
    'up' => "
        CREATE TABLE `stock_movements` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `movement_type` ENUM('receive','transfer_in','transfer_out','sale','pos_sale','return','adjustment','damaged','count_correction','reservation','reservation_release') NOT NULL,
            `quantity` INT NOT NULL,
            `quantity_before` INT NOT NULL DEFAULT 0,
            `quantity_after` INT NOT NULL DEFAULT 0,
            `reference_type` VARCHAR(100) DEFAULT NULL,
            `reference_id` BIGINT UNSIGNED DEFAULT NULL,
            `unit_cost` DECIMAL(12,2) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_movements_prod_loc` (`product_id`, `location_id`),
            INDEX `idx_movements_type` (`movement_type`),
            INDEX `idx_movements_ref` (`reference_type`, `reference_id`),
            INDEX `idx_movements_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_movements`",
];
