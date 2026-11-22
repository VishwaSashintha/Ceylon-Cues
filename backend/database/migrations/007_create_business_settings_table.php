<?php

/**
 * Migration: Create business_settings table
 * Key-value store for all configurable business parameters.
 */

return [
    'up' => "
        CREATE TABLE `business_settings` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL UNIQUE,
            `setting_value` TEXT DEFAULT NULL,
            `setting_type` ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
            `description` TEXT DEFAULT NULL,
            `is_public` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `business_settings`",
];
