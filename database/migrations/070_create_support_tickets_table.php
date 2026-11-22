<?php

declare(strict_types=1);

/**
 * Migration: Create support_tickets table
 */

return [
    'up' => "
        CREATE TABLE `support_tickets` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `order_id` BIGINT UNSIGNED DEFAULT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `category` ENUM('order','product','delivery','payment','return','general','wholesale') NOT NULL DEFAULT 'general',
            `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            `status` ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
            `customer_name` VARCHAR(100) DEFAULT NULL,
            `customer_email` VARCHAR(255) DEFAULT NULL,
            `customer_phone` VARCHAR(30) DEFAULT NULL,
            `assigned_to` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `closed_at` TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_support_status` (`status`),
            INDEX `idx_support_priority` (`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `support_tickets`",
];
