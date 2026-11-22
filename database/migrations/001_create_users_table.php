<?php

/**
 * Migration: Create users table
 * Core authentication and user management table.
 */

return [
    'up' => "
        CREATE TABLE `users` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `uuid` CHAR(36) NOT NULL UNIQUE,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `phone` VARCHAR(20) DEFAULT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `last_name` VARCHAR(100) NOT NULL,
            `role` ENUM('owner','shop_manager','shop_cashier','warehouse_manager','inventory_staff','order_staff','customer_support','finance_staff','customer') NOT NULL DEFAULT 'customer',
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `is_owner` BOOLEAN NOT NULL DEFAULT 0,
            `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
            `last_login_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_users_role` (`role`),
            INDEX `idx_users_email` (`email`),
            INDEX `idx_users_phone` (`phone`),
            INDEX `idx_users_uuid` (`uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `users`",
];
