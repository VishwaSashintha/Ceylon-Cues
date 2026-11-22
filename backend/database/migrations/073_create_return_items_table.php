<?php

declare(strict_types=1);

/**
 * Migration: Create return_items table
 */

return [
    'up' => "
        CREATE TABLE `return_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `return_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `quantity` INT NOT NULL,
            `condition_notes` TEXT DEFAULT NULL,
            `restock` BOOLEAN NOT NULL DEFAULT 0,
            FOREIGN KEY (`return_id`) REFERENCES `return_requests` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `return_items`",
];
