<?php

declare(strict_types=1);

/**
 * Migration: Create order_items table
 * Preserves historical snapshot of products, SKUs, and costs.
 */

return [
    'up' => "
        CREATE TABLE `order_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `sku_snapshot` VARCHAR(100) NOT NULL,
            `product_name_snapshot` VARCHAR(255) NOT NULL,
            `variant_name_snapshot` VARCHAR(100) DEFAULT NULL,
            `quantity` INT NOT NULL,
            `unit_price` DECIMAL(12,2) NOT NULL,
            `discount_per_item` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_price` DECIMAL(14,2) NOT NULL,
            `cost_price_snapshot` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `location_id` BIGINT UNSIGNED DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `order_items`",
];
