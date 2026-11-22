<?php

declare(strict_types=1);

/**
 * Migration: Create orders table
 * Central order engine supporting online, pos, wholesale, and manual channels.
 */

return [
    'up' => "
        CREATE TABLE `orders` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `channel` ENUM('online','pos','wholesale','manual') NOT NULL,
            `status` ENUM('pending','confirmed','processing','ready_to_ship','shipped','out_for_delivery','delivered','ready_for_collection','collected','cancelled','returned','refunded') NOT NULL DEFAULT 'pending',
            `payment_status` ENUM('unpaid','awaiting_verification','paid','partially_paid','refunded','failed') NOT NULL DEFAULT 'unpaid',
            `customer_name` VARCHAR(200) NOT NULL,
            `customer_email` VARCHAR(255) DEFAULT NULL,
            `customer_phone` VARCHAR(30) NOT NULL,
            `shipping_address_id` BIGINT UNSIGNED DEFAULT NULL,
            `billing_address` TEXT DEFAULT NULL,
            `shipping_address_snapshot` JSON DEFAULT NULL,
            `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `discount_code` VARCHAR(50) DEFAULT NULL,
            `delivery_charge` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `currency` VARCHAR(3) NOT NULL DEFAULT 'LKR',
            `delivery_method` ENUM('home_delivery','courier','shop_collection','warehouse_dispatch') DEFAULT NULL,
            `delivery_zone_id` BIGINT UNSIGNED DEFAULT NULL,
            `fulfilment_location_id` BIGINT UNSIGNED DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `internal_notes` TEXT DEFAULT NULL,
            `cancelled_reason` TEXT DEFAULT NULL,
            `created_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`delivery_zone_id`) REFERENCES `delivery_zones` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`fulfilment_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_orders_ref` (`reference_number`),
            INDEX `idx_orders_user` (`user_id`),
            INDEX `idx_orders_channel` (`channel`),
            INDEX `idx_orders_status` (`status`),
            INDEX `idx_orders_payment_status` (`payment_status`),
            INDEX `idx_orders_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `orders`",
];
