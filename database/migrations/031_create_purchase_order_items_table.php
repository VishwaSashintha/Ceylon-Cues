<?php

declare(strict_types=1);

/**
 * Migration: Create purchase_order_items table
 */

return [
    'up' => "
        CREATE TABLE `purchase_order_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_order_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `quantity_ordered` INT NOT NULL,
            `quantity_received` INT NOT NULL DEFAULT 0,
            `unit_cost` DECIMAL(12,2) NOT NULL,
            `total_cost` DECIMAL(14,2) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `purchase_order_items`",
];
