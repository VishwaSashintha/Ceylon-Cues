<?php

/**
 * Migration: Create product_cost_history table
 * Audit trail for product cost price changes.
 */

return [
    'up' => "
        CREATE TABLE `product_cost_history` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `old_cost` DECIMAL(12,2) NOT NULL,
            `new_cost` DECIMAL(12,2) NOT NULL,
            `changed_by` BIGINT UNSIGNED NOT NULL,
            `reason` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL,
            FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `product_cost_history`",
];
