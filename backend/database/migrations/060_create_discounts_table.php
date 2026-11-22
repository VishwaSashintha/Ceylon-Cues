<?php

declare(strict_types=1);

/**
 * Migration: Create discounts table
 */

return [
    'up' => "
        CREATE TABLE `discounts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `code` VARCHAR(50) DEFAULT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `type` ENUM('percentage','fixed','free_delivery') NOT NULL,
            `value` DECIMAL(12,2) NOT NULL,
            `applies_to` ENUM('order','product','category') NOT NULL DEFAULT 'order',
            `target_id` BIGINT UNSIGNED DEFAULT NULL,
            `min_order_amount` DECIMAL(14,2) DEFAULT NULL,
            `max_discount_amount` DECIMAL(14,2) DEFAULT NULL,
            `max_uses` INT DEFAULT NULL,
            `used_count` INT NOT NULL DEFAULT 0,
            `max_uses_per_customer` INT NOT NULL DEFAULT 1,
            `is_first_order_only` BOOLEAN NOT NULL DEFAULT 0,
            `start_date` DATETIME DEFAULT NULL,
            `end_date` DATETIME DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `is_stackable` BOOLEAN NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            INDEX `idx_disc_code` (`code`),
            INDEX `idx_disc_active` (`is_active`, `start_date`, `end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `discounts`",
];
