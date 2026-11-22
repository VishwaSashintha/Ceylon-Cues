<?php

declare(strict_types=1);

/**
 * Migration: Create discount_usage table
 */

return [
    'up' => "
        CREATE TABLE `discount_usage` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `discount_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `discount_amount` DECIMAL(14,2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `discount_usage`",
];
