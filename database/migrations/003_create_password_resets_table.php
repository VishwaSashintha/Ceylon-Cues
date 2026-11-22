<?php

/**
 * Migration: Create password_resets table
 * Stores password reset tokens with expiry.
 */

return [
    'up' => "
        CREATE TABLE `password_resets` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `token` VARCHAR(255) NOT NULL UNIQUE,
            `expires_at` TIMESTAMP NOT NULL,
            `used_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `password_resets`",
];
