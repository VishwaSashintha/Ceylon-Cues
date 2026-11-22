<?php

declare(strict_types=1);

/**
 * Migration: Create daily_summaries table
 */

return [
    'up' => "
        CREATE TABLE `daily_summaries` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `summary_date` DATE NOT NULL UNIQUE,
            `total_online_orders` INT NOT NULL DEFAULT 0,
            `total_pos_sales` INT NOT NULL DEFAULT 0,
            `total_wholesale_orders` INT NOT NULL DEFAULT 0,
            `online_revenue` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `pos_revenue` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `wholesale_revenue` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_revenue` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_expenses` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `total_refunds` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `items_sold` INT NOT NULL DEFAULT 0,
            `new_customers` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_summary_date` (`summary_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `daily_summaries`",
];
