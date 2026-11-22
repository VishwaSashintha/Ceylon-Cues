<?php

declare(strict_types=1);

/**
 * Migration: Create expenses table
 */

return [
    'up' => "
        CREATE TABLE `expenses` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `category` ENUM('rent','utilities','salaries','marketing','shipping','supplies','maintenance','other') NOT NULL,
            `description` TEXT NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `expense_date` DATE NOT NULL,
            `receipt_path` VARCHAR(500) DEFAULT NULL,
            `approved_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            INDEX `idx_expenses_date` (`expense_date`),
            INDEX `idx_expenses_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `expenses`",
];
