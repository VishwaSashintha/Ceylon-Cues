<?php

/**
 * Migration: Create product_variants table
 * Product variations (e.g., weight, tip size, color).
 */

return [
    'up' => "
        CREATE TABLE `product_variants` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_name` VARCHAR(255) NOT NULL,
            `sku_suffix` VARCHAR(50) NOT NULL,
            `barcode` VARCHAR(100) DEFAULT NULL UNIQUE,
            `additional_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `additional_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_variant_product_name` (`product_id`, `variant_name`),
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `product_variants`",
];
