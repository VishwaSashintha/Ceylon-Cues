<?php

declare(strict_types=1);

/**
 * Migration: Create campaign_products table
 */

return [
    'up' => "
        CREATE TABLE `campaign_products` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `campaign_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `special_price` DECIMAL(12,2) DEFAULT NULL,
            `limited_quantity` INT DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            UNIQUE KEY `unique_campaign_product` (`campaign_id`, `product_id`),
            FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `campaign_products`",
];
