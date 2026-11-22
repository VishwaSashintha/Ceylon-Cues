<?php

declare(strict_types=1);

/**
 * Migration: Create wholesale_enquiries table
 */

return [
    'up' => "
        CREATE TABLE `wholesale_enquiries` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `company_name` VARCHAR(255) NOT NULL,
            `contact_name` VARCHAR(200) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `phone` VARCHAR(30) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('new','reviewing','quoted','accepted','rejected','closed') NOT NULL DEFAULT 'new',
            `assigned_to` BIGINT UNSIGNED DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `wholesale_enquiries`",
];
