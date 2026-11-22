<?php

declare(strict_types=1);

/**
 * Migration: Create csrf_tokens table
 */

return [
    'up' => "
        CREATE TABLE `csrf_tokens` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `token` VARCHAR(255) NOT NULL UNIQUE,
            `session_id` VARCHAR(255) DEFAULT NULL,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `expires_at` TIMESTAMP NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            INDEX `idx_csrf_lookup` (`token`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `csrf_tokens`",
];
