<?php

/**
 * Migration: Create login_attempts table
 * Rate-limits and tracks login attempts by IP.
 */

return [
    'up' => "
        CREATE TABLE `login_attempts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `ip_address` VARCHAR(45) NOT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `success` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_login_attempts_ip_time` (`ip_address`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `login_attempts`",
];
