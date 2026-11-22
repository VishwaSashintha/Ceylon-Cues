<?php

declare(strict_types=1);

/**
 * Migration: Create landed_costs table
 */

return [
    'up' => "
        CREATE TABLE `landed_costs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_order_id` BIGINT UNSIGNED NOT NULL,
            `cost_type` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(14,2) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `landed_costs`",
];
