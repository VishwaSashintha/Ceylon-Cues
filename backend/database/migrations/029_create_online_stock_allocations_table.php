<?php

declare(strict_types=1);

/**
 * Migration: Create online_stock_allocations table
 * Website stock visibility and allocation system.
 */

return [
    'up' => "
        CREATE TABLE `online_stock_allocations` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `allocated_quantity` INT NOT NULL DEFAULT 0,
            `min_online_quantity` INT NOT NULL DEFAULT 0,
            `allow_backorder` BOOLEAN NOT NULL DEFAULT 0,
            `allow_preorder` BOOLEAN NOT NULL DEFAULT 0,
            `is_visible_online` BOOLEAN NOT NULL DEFAULT 1,
            `source_location_id` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_online_allocation` (`product_id`, `variant_id`),
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`source_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE SET NULL,
            INDEX `idx_online_visible` (`is_visible_online`, `allocated_quantity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `online_stock_allocations`",
];
