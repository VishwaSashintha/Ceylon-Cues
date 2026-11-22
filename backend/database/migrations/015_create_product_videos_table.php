<?php

/**
 * Migration: Create product_videos table
 * Product video content (embedded URLs or uploaded files).
 */

return [
    'up' => "
        CREATE TABLE `product_videos` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `video_url` VARCHAR(500) DEFAULT NULL,
            `file_path` VARCHAR(500) DEFAULT NULL,
            `title` VARCHAR(255) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `product_videos`",
];
