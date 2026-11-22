<?php

declare(strict_types=1);

/**
 * Migration: Create goods_receipts table
 */

return [
    'up' => "
        CREATE TABLE `goods_receipts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `purchase_order_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `received_by` BIGINT UNSIGNED NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `goods_receipts`",
];
