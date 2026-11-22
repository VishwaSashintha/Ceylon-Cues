<?php

declare(strict_types=1);

/**
 * Migration: Create product_reviews table
 */

return [
    'up' => "
        CREATE TABLE `product_reviews` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `rating` TINYINT UNSIGNED NOT NULL,
            `review_text` TEXT DEFAULT NULL,
            `is_approved` BOOLEAN NOT NULL DEFAULT 0,
            `is_verified_purchase` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_product_review` (`product_id`, `user_id`),
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_reviews_product_approved` (`product_id`, `is_approved`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `product_reviews`",
];
