<?php

declare(strict_types=1);

/**
 * Migration: Create customer_addresses table
 */

return [
    'up' => "
        CREATE TABLE `customer_addresses` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `label` VARCHAR(50) NOT NULL DEFAULT 'Home',
            `recipient_name` VARCHAR(100) NOT NULL,
            `phone` VARCHAR(20) NOT NULL,
            `address_line1` VARCHAR(255) NOT NULL,
            `address_line2` VARCHAR(255) DEFAULT NULL,
            `city` VARCHAR(100) NOT NULL,
            `district` VARCHAR(100) NOT NULL,
            `postal_code` VARCHAR(20) DEFAULT NULL,
            `is_default` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_cust_addr_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `customer_addresses`",
];
