<?php

declare(strict_types=1);

/**
 * Migration 101: Screen Features, Foreign Currency POs & POS Extensions
 */

return [
    'up' => "
        -- 1. Foreign-currency purchasing and landed cost on purchase_orders
        ALTER TABLE `purchase_orders`
            ADD COLUMN IF NOT EXISTS `currency_code` VARCHAR(10) NOT NULL DEFAULT 'LKR' AFTER `supplier_id`,
            ADD COLUMN IF NOT EXISTS `exchange_rate` DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER `currency_code`,
            ADD COLUMN IF NOT EXISTS `duty_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `exchange_rate`,
            ADD COLUMN IF NOT EXISTS `freight_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `duty_percent`,
            ADD COLUMN IF NOT EXISTS `landed_cost_lkr` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `freight_cost`;

        -- 2. POS Held Sales table
        CREATE TABLE IF NOT EXISTS `pos_held_sales` (
            `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_id` BIGINT UNSIGNED NOT NULL,
            `label`      VARCHAR(100) DEFAULT NULL,
            `cart_data`  JSON NOT NULL,
            `held_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_held_session` (`session_id`),
            FOREIGN KEY (`session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 3. POS Registered Terminals table
        CREATE TABLE IF NOT EXISTS `pos_terminals` (
            `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `terminal_id`   VARCHAR(50) NOT NULL UNIQUE,
            `name`          VARCHAR(100) NOT NULL,
            `location_id`   BIGINT UNSIGNED NOT NULL,
            `is_active`     BOOLEAN NOT NULL DEFAULT 1,
            `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_seen_at`  TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 4. POS Exchanges and return credit on pos_transactions
        ALTER TABLE `pos_transactions`
            ADD COLUMN IF NOT EXISTS `transaction_type` ENUM('sale','void','exchange','refund') NOT NULL DEFAULT 'sale' AFTER `session_id`,
            ADD COLUMN IF NOT EXISTS `return_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `change_given`,
            ADD COLUMN IF NOT EXISTS `exchange_ref` VARCHAR(100) DEFAULT NULL AFTER `return_amount`,
            ADD COLUMN IF NOT EXISTS `voided_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`,
            ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) DEFAULT NULL AFTER `voided_at`;
    ",
    'down' => "
        DROP TABLE IF EXISTS `pos_held_sales`;
        DROP TABLE IF EXISTS `pos_terminals`;
        ALTER TABLE `purchase_orders`
            DROP COLUMN IF EXISTS `landed_cost_lkr`,
            DROP COLUMN IF EXISTS `freight_cost`,
            DROP COLUMN IF EXISTS `duty_percent`,
            DROP COLUMN IF EXISTS `exchange_rate`,
            DROP COLUMN IF EXISTS `currency_code`;
        ALTER TABLE `pos_transactions`
            DROP COLUMN IF EXISTS `void_reason`,
            DROP COLUMN IF EXISTS `voided_at`,
            DROP COLUMN IF EXISTS `exchange_ref`,
            DROP COLUMN IF EXISTS `return_amount`,
            DROP COLUMN IF EXISTS `transaction_type`;
    ",
];
