<?php

declare(strict_types=1);

/**
 * Migration: Create campaigns table
 */

return [
    'up' => "
        CREATE TABLE `campaigns` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `type` ENUM('featured','sale','seasonal','limited_availability','bundle') NOT NULL DEFAULT 'sale',
            `start_date` DATETIME NOT NULL,
            `end_date` DATETIME NOT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `settings` JSON DEFAULT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `campaigns`",
];
