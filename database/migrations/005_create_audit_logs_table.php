<?php

/**
 * Migration: Create audit_logs table
 * Tracks all significant system actions for audit trail.
 */

return [
    'up' => "
        CREATE TABLE `audit_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED DEFAULT NULL,
            `action` VARCHAR(100) NOT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `old_values` JSON DEFAULT NULL,
            `new_values` JSON DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_audit_user_id` (`user_id`),
            INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
            INDEX `idx_audit_action` (`action`),
            INDEX `idx_audit_created_at` (`created_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `audit_logs`",
];
