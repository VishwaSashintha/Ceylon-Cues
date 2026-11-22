<?php

/**
 * Migration: Create permissions table
 * Role-based access control per module.
 */

return [
    'up' => "
        CREATE TABLE `permissions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `role` VARCHAR(50) NOT NULL,
            `module` VARCHAR(100) NOT NULL,
            `can_view` BOOLEAN NOT NULL DEFAULT 0,
            `can_create` BOOLEAN NOT NULL DEFAULT 0,
            `can_edit` BOOLEAN NOT NULL DEFAULT 0,
            `can_delete` BOOLEAN NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_permissions_role_module` (`role`, `module`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `permissions`",
];
