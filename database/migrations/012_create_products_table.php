<?php

/**
 * Migration: Create products table
 * Core product catalog with pricing, quality tiers, and publishing.
 */

return [
    'up' => "
        CREATE TABLE `products` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `sku` VARCHAR(100) NOT NULL UNIQUE,
            `barcode` VARCHAR(100) DEFAULT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `category_id` BIGINT UNSIGNED NOT NULL,
            `supplier_id` BIGINT UNSIGNED DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `specifications` JSON DEFAULT NULL,
            `quality_tier` ENUM('budget','standard','premium') NOT NULL DEFAULT 'standard',
            `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `retail_price` DECIMAL(12,2) NOT NULL,
            `wholesale_price` DECIMAL(12,2) DEFAULT NULL,
            `weight_kg` DECIMAL(8,3) DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `is_published` BOOLEAN NOT NULL DEFAULT 0,
            `is_featured` BOOLEAN NOT NULL DEFAULT 0,
            `min_wholesale_qty` INT NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_products_category_id` (`category_id`),
            INDEX `idx_products_supplier_id` (`supplier_id`),
            INDEX `idx_products_sku` (`sku`),
            INDEX `idx_products_barcode` (`barcode`),
            INDEX `idx_products_slug` (`slug`),
            FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `products`",
];
