<?php

/**
 * Migration: Create categories table
 * Hierarchical product categories with self-referencing parent.
 */

return [
    'up' => "
        CREATE TABLE `categories` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `parent_id` BIGINT UNSIGNED DEFAULT NULL,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `description` TEXT DEFAULT NULL,
            `image_path` VARCHAR(500) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `categories`",
];
