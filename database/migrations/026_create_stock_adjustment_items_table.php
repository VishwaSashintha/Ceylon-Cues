<?php

declare(strict_types=1);

/**
 * Migration: Create stock_adjustment_items table
 */

return [
    'up' => "
        CREATE TABLE `stock_adjustment_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `adjustment_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `quantity_before` INT NOT NULL,
            `quantity_change` INT NOT NULL,
            `quantity_after` INT NOT NULL,
            `reason` TEXT DEFAULT NULL,
            FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `stock_adjustment_items`",
];
