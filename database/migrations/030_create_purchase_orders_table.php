<?php

declare(strict_types=1);

/**
 * Migration: Create purchase_orders table
 */

return [
    'up' => "
        CREATE TABLE `purchase_orders` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `reference_number` VARCHAR(50) NOT NULL UNIQUE,
            `supplier_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM('draft','submitted','confirmed','partial_received','received','cancelled') NOT NULL DEFAULT 'draft',
            `expected_date` DATE DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `shipping_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_by` BIGINT UNSIGNED NOT NULL,
            `approved_by` BIGINT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            INDEX `idx_po_status` (`status`),
            INDEX `idx_po_supplier` (`supplier_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `purchase_orders`",
];
