<?php

declare(strict_types=1);

/**
 * Migration: Create rate_limits table
 */

return [
    'up' => "
        CREATE TABLE `rate_limits` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `identifier` VARCHAR(255) NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `last_attempt_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_id_action` (`identifier`, `action`),
            INDEX `idx_ratelimit_expiry` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `rate_limits`",
];
