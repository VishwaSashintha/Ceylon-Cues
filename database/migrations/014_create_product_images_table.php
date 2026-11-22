<?php

/**
 * Migration: Create product_images table
 * Product image gallery with sorting and primary flag.
 */

return [
    'up' => "
        CREATE TABLE `product_images` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `file_path` VARCHAR(500) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `alt_text` VARCHAR(255) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_primary` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `product_images`",
];
