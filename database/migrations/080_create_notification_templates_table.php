<?php

declare(strict_types=1);

/**
 * Migration: Create notification_templates table
 */

return [
    'up' => "
        CREATE TABLE `notification_templates` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `channel` ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp',
            `event_type` VARCHAR(100) NOT NULL,
            `template_body` TEXT NOT NULL,
            `variables` JSON DEFAULT NULL,
            `whatsapp_template_name` VARCHAR(255) DEFAULT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_notif_tmpl_event` (`event_type`, `channel`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'down' => "DROP TABLE IF EXISTS `notification_templates`",
];
