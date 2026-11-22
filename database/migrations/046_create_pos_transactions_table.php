<?php

declare(strict_types=1);

/**
 * Migration: Create pos_transactions table
 */

return [
    'up' => "
        CREATE TABLE `pos_transactions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `session_id` BIGINT UNSIGNED NOT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `cashier_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `payment_method` ENUM('cash','card','bank_transfer','split') NOT NULL DEFAULT 'cash',
            `cash_received` DECIMAL(14,2) DEFAULT NULL,
            `change_given` DECIMAL(14,2) DEFAULT NULL,
            `card_reference` VARCHAR(100) DEFAULT NULL,
            `is_return` BOOLEAN NOT NULL DEFAULT 0,
            `return_reason` TEXT DEFAULT NULL,
            `original_transaction_id` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`original_transaction_id`) REFERENCES `pos_transactions` (`id`) ON DELETE SET NULL,
            INDEX `idx_postx_ref` (`reference_number`),
            INDEX `idx_postx_session` (`session_id`),
            INDEX `idx_postx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `pos_transactions`",
];
