<?php

declare(strict_types=1);

/**
 * Migration: Create goods_receipt_items table
 */

return [
    'up' => "
        CREATE TABLE `goods_receipt_items` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `receipt_id` BIGINT UNSIGNED NOT NULL,
            `purchase_order_item_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `variant_id` BIGINT UNSIGNED DEFAULT NULL,
            `quantity_received` INT NOT NULL,
            `unit_cost` DECIMAL(12,2) NOT NULL,
            `condition_notes` TEXT DEFAULT NULL,
            FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
            FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `goods_receipt_items`",
];
