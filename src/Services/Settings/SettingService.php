<?php

declare(strict_types=1);

namespace CeylonCues\Services\Settings;

use CeylonCues\Core\Database;
use PDO;

/**
 * Business Settings Store
 */
final class SettingService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAllSettings(bool $publicOnly = false): array
    {
        $where = $publicOnly ? 'WHERE is_public = 1' : '';
        $stmt = $this->db->query("SELECT setting_key, setting_value, setting_type, description, is_public FROM business_settings {$where}");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        return $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM business_settings WHERE setting_key = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO business_settings (setting_key, setting_value)
            VALUES (:key, :val)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $stmt->execute([':key' => $key, ':val' => $value]);
    }
}
