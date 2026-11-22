<?php

declare(strict_types=1);

/**
 * Migration: Create inventory_stock table
 * Location-specific stock balances for each product & variant.
 */

return [
    'up' => "
        CREATE TABLE `inventory_stock` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `quantity` INT NOT NULL DEFAULT 0,
            `reserved_quantity` INT NOT NULL DEFAULT 0,
            `damaged_quantity` INT NOT NULL DEFAULT 0,
            `reorder_level` INT NOT NULL DEFAULT 0,
            `min_stock_level` INT NOT NULL DEFAULT 0,
            `max_stock_level` INT DEFAULT NULL,
            `last_counted_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_stock_product_variant_location` (`product_id`, `variant_id`, `location_id`),
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            INDEX `idx_stock_quantity` (`quantity`),
            INDEX `idx_stock_location` (`location_id`),
            INDEX `idx_stock_reorder` (`quantity`, `reorder_level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `inventory_stock`",
];
