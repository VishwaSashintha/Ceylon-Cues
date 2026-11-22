<?php

declare(strict_types=1);

/**
 * Migration: Create bank_transfer_submissions table
 * Customer manual bank transfer proof upload and owner verification.
 */

return [
    'up' => "
        CREATE TABLE `bank_transfer_submissions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `payment_id` BIGINT UNSIGNED NOT NULL,
            `bank_name` VARCHAR(100) DEFAULT NULL,
            `account_holder` VARCHAR(255) DEFAULT NULL,
            `transfer_reference` VARCHAR(255) DEFAULT NULL,
            `transfer_date` DATE DEFAULT NULL,
            `evidence_file_path` VARCHAR(500) DEFAULT NULL,
            `evidence_file_name` VARCHAR(255) DEFAULT NULL,
            `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `verification_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `verified_by` BIGINT UNSIGNED DEFAULT NULL,
            `verified_at` TIMESTAMP NULL DEFAULT NULL,
            `rejection_reason` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_bts_status` (`verification_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `bank_transfer_submissions`",
];
