<?php

/**
 * Migration: Create suppliers table
 * Tracks product suppliers and vendors.
 */

return [
    'up' => "
        CREATE TABLE `suppliers` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `company_name` VARCHAR(255) NOT NULL,
            `contact_person` VARCHAR(255) DEFAULT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `phone` VARCHAR(20) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `city` VARCHAR(100) DEFAULT NULL,
            `country` VARCHAR(100) NOT NULL DEFAULT 'Sri Lanka',
            `notes` TEXT DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `suppliers`",
];
