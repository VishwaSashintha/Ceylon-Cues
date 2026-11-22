<?php

declare(strict_types=1);

/**
 * Migration 100: Security Hardening, POS Extensions & Enterprise Features
 */

return [
    'up' => "
        -- 1. Webhook signature on payments table
        ALTER TABLE `payments`
            ADD COLUMN `webhook_signature` VARCHAR(255) NULL AFTER `gateway_response`;

        -- 2. Private upload metadata on bank_transfer_submissions table
        ALTER TABLE `bank_transfer_submissions`
            ADD COLUMN `stored_proof_path` VARCHAR(255) NULL AFTER `evidence_file_path`,
            ADD COLUMN `mime_type` VARCHAR(50) NULL AFTER `stored_proof_path`,
            ADD COLUMN `file_size_bytes` INT NULL AFTER `mime_type`;

        -- 3. SEO fields on products table
        ALTER TABLE `products`
            ADD COLUMN `meta_title` VARCHAR(255) NULL AFTER `description`,
            ADD COLUMN `meta_description` TEXT NULL AFTER `meta_title`;

        -- 4. Tax Invoices, VAT & Expiration on orders table
        ALTER TABLE `orders`
            ADD COLUMN `tax_invoice_number` VARCHAR(50) NULL UNIQUE AFTER `reference_number`,
            ADD COLUMN `vat_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`,
            ADD COLUMN `vat_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `vat_percentage`,
            ADD COLUMN `tracking_token` VARCHAR(64) NULL UNIQUE AFTER `tax_invoice_number`,
            ADD COLUMN `carrier_name` VARCHAR(100) NULL AFTER `notes`,
            ADD COLUMN `carrier_tracking_number` VARCHAR(100) NULL AFTER `carrier_name`,
            ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`,
            ADD INDEX `idx_orders_tracking_token` (`tracking_token`),
            ADD INDEX `idx_orders_expires_at` (`expires_at`, `status`);

        -- 5. POS session floats and cash movements
        ALTER TABLE `pos_sessions`
            ADD COLUMN `terminal_id` VARCHAR(50) NULL AFTER `location_id`,
            ADD COLUMN `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `opening_balance`,
            ADD COLUMN `total_cash_in` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `opening_float`,
            ADD COLUMN `total_cash_out` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `total_cash_in`;

        ALTER TABLE `pos_transactions`
            ADD COLUMN IF NOT EXISTS `offline_ref` VARCHAR(100) DEFAULT NULL AFTER `card_reference`;

        -- 6. Manager PIN overrides audit ledger
        CREATE TABLE IF NOT EXISTS `pos_overrides` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_id` BIGINT UNSIGNED NOT NULL,
            `cashier_id` BIGINT UNSIGNED NOT NULL,
            `manager_id` BIGINT UNSIGNED NOT NULL,
            `action` ENUM('void_sale','discount_override','price_override','refund_override','drawer_open') NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `reason` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            INDEX `idx_overrides_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 7. Registered POS terminals
        CREATE TABLE IF NOT EXISTS `pos_terminals` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `terminal_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 8. Customer Wishlists
        CREATE TABLE IF NOT EXISTS `wishlists` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_user_product_wishlist` (`user_id`, `product_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 9. Back-in-stock notification requests
        CREATE TABLE IF NOT EXISTS `stock_notification_requests` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `email` VARCHAR(255) DEFAULT NULL,
            `phone` VARCHAR(30) DEFAULT NULL,
            `is_notified` BOOLEAN NOT NULL DEFAULT 0,
            `notified_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            INDEX `idx_stock_notif_pending` (`product_id`, `is_notified`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 10. Courier Cash on Delivery (COD) Remittances
        CREATE TABLE IF NOT EXISTS `cod_remittances` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `courier_name` VARCHAR(100) NOT NULL,
            `remittance_reference` VARCHAR(100) NOT NULL,
            `total_collected` DECIMAL(14,2) NOT NULL,
            `total_fee` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `net_remitted` DECIMAL(14,2) NOT NULL,
            `remittance_date` DATE NOT NULL,
            `is_reconciled` BOOLEAN NOT NULL DEFAULT 0,
            `reconciled_by` BIGINT UNSIGNED DEFAULT NULL,
            `reconciled_at` TIMESTAMP NULL DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_cod_reconciled` (`is_reconciled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 11. Supplier Payments Ledger
        CREATE TABLE IF NOT EXISTS `supplier_payments` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `supplier_id` BIGINT UNSIGNED NOT NULL,
            `purchase_order_id` BIGINT UNSIGNED DEFAULT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `payment_method` ENUM('bank_transfer','cheque','cash') NOT NULL DEFAULT 'bank_transfer',
            `payment_reference` VARCHAR(100) DEFAULT NULL,
            `payment_date` DATE NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 12. Purchase Returns to Suppliers
        CREATE TABLE IF NOT EXISTS `purchase_returns` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `supplier_id` BIGINT UNSIGNED NOT NULL,
            `purchase_order_id` BIGINT UNSIGNED DEFAULT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('draft','approved','dispatched','credited','cancelled') NOT NULL DEFAULT 'draft',
            `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `reason` TEXT NOT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `purchase_return_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_return_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `quantity` INT NOT NULL,
            `unit_cost` DECIMAL(14,2) NOT NULL,
            `total_cost` DECIMAL(14,2) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 13. CMS Banners and Pages
        CREATE TABLE IF NOT EXISTS `cms_banners` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `subtitle` VARCHAR(255) DEFAULT NULL,
            `image_url` VARCHAR(255) NOT NULL,
            `link_url` VARCHAR(255) DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `cms_pages` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `slug` VARCHAR(100) NOT NULL UNIQUE,
            `title` VARCHAR(255) NOT NULL,
            `content` LONGTEXT NOT NULL,
            `meta_title` VARCHAR(255) DEFAULT NULL,
            `meta_description` TEXT DEFAULT NULL,
            `is_published` BOOLEAN NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `pos_cash_movements` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `session_id` BIGINT UNSIGNED NOT NULL,
            `type` ENUM('in','out') NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`session_id`) REFERENCES `pos_sessions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `pos_transactions_payments` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `pos_transaction_id` BIGINT UNSIGNED NOT NULL,
            `payment_method` VARCHAR(50) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_pos_tx` (`pos_transaction_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS `pos_transactions_payments`;
        DROP TABLE IF EXISTS `pos_cash_movements`;
        DROP TABLE IF EXISTS `cms_pages`;
        DROP TABLE IF EXISTS `cms_banners`;
        DROP TABLE IF EXISTS `purchase_return_items`;
        DROP TABLE IF EXISTS `purchase_returns`;
        DROP TABLE IF EXISTS `supplier_payments`;
        DROP TABLE IF EXISTS `cod_remittances`;
        DROP TABLE IF EXISTS `stock_notification_requests`;
        DROP TABLE IF EXISTS `wishlists`;
        DROP TABLE IF EXISTS `pos_terminals`;
        DROP TABLE IF EXISTS `pos_overrides`;
    ",
];
