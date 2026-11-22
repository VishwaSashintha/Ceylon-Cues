<?php

declare(strict_types=1);

/**
 * Migration: Create payments table
 * Handles multi-gateway, offline, bank transfer, and POS payments.
 */

return [
    'up' => "
        CREATE TABLE `payments` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `payment_method` ENUM('visa','mastercard','koko','mintpay','cod','bank_transfer','cash','card') NOT NULL,
            `payment_provider` VARCHAR(50) DEFAULT NULL,
            `provider_transaction_id` VARCHAR(255) DEFAULT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `currency` VARCHAR(3) NOT NULL DEFAULT 'LKR',
            `status` ENUM('pending','processing','completed','failed','refunded','partially_refunded','cancelled') NOT NULL DEFAULT 'pending',
            `paid_at` TIMESTAMP NULL DEFAULT NULL,
            `verified_by` BIGINT UNSIGNED DEFAULT NULL,
            `verified_at` TIMESTAMP NULL DEFAULT NULL,
            `gateway_response` JSON DEFAULT NULL,
            `idempotency_key` VARCHAR(255) UNIQUE DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_payments_order` (`order_id`),
            INDEX `idx_payments_status` (`status`),
            INDEX `idx_payments_method` (`payment_method`),
            INDEX `idx_payments_provider_tx` (`provider_transaction_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `payments`",
];
