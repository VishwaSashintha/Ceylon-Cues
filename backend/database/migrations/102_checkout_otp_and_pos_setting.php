<?php

declare(strict_types=1);

/**
 * Migration 102: Checkout OTP verification table + POS enabled business setting
 */

return [
    'up' => "
        CREATE TABLE IF NOT EXISTS `checkout_otps` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`      INT UNSIGNED NOT NULL,
            `email`        VARCHAR(255) NOT NULL,
            `code_hash`    VARCHAR(255) NOT NULL,
            `payload_hash` VARCHAR(64)  NOT NULL,
            `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `expires_at`   DATETIME NOT NULL,
            `used_at`      DATETIME DEFAULT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_otp_user`    (`user_id`),
            INDEX `idx_otp_email`   (`email`),
            INDEX `idx_otp_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        INSERT IGNORE INTO `business_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `is_public`, `created_at`)
        VALUES ('pos_enabled', '0', 'boolean', 'Enable the Point-of-Sale terminal for the physical retail shop', 0, NOW())
    ",
];
