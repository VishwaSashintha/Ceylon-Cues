<?php

declare(strict_types=1);

/**
 * Migration: Create stock_count_items table
 */

return [
    'up' => "
        CREATE TABLE `stock_count_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `count_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `system_quantity` INT NOT NULL,
            `counted_quantity` INT NOT NULL,
            `variance` INT NOT NULL,
            `notes` TEXT DEFAULT NULL,
            FOREIGN KEY (`count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_count_items`",
];
