<?php

declare(strict_types=1);

namespace CeylonCues\Services\Auth;

use CeylonCues\Core\Database;
use PDO;

/**
 * Role-Based Access Control Service.
 *
 * Enforces role and module-level permissions.
 * The business owner always bypasses all permission checks.
 */
final class RbacService
{
    private PDO $db;

    public const ROLE_OWNER = 'owner';
    public const ROLE_SHOP_MANAGER = 'shop_manager';
    public const ROLE_SHOP_CASHIER = 'shop_cashier';
    public const ROLE_WAREHOUSE_MANAGER = 'warehouse_manager';
    public const ROLE_INVENTORY_STAFF = 'inventory_staff';
    public const ROLE_ORDER_STAFF = 'order_staff';
    public const ROLE_CUSTOMER_SUPPORT = 'customer_support';
    public const ROLE_FINANCE_STAFF = 'finance_staff';
    public const ROLE_CUSTOMER = 'customer';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Check if a user has permission for a specific module action.
     *
     * @param array  $user   User array (must contain 'role' and 'is_owner')
     * @param string $module Module name (e.g., 'products', 'inventory', 'pos')
     * @param string $action Action: 'view', 'create', 'edit', 'delete'
     */
    public function hasPermission(array $user, string $module, string $action = 'view'): bool
    {
        // Owner always has full permission across all modules
        if (!empty($user['is_owner']) || ($user['role'] ?? '') === self::ROLE_OWNER) {
            return true;
        }

        $role = $user['role'] ?? '';
        if (empty($role) || $role === self::ROLE_CUSTOMER) {
            return false;
        }

        $col = match ($action) {
            'create' => 'can_create',
            'edit'   => 'can_edit',
            'delete' => 'can_delete',
            default  => 'can_view',
        };

        $stmt = $this->db->prepare("SELECT {$col} FROM permissions WHERE role = :role AND module = :module LIMIT 1");
        $stmt->execute([':role' => $role, ':module' => $module]);
        $val = $stmt->fetchColumn();

        return (bool)$val;
    }

    /**
     * Get all assigned permissions for a given role.
     */
    public function getRolePermissions(string $role): array
    {
        $stmt = $this->db->prepare("SELECT module, can_view, can_create, can_edit, can_delete FROM permissions WHERE role = :role");
        $stmt->execute([':role' => $role]);
        return $stmt->fetchAll();
    }

    /**
     * Update permissions for an employee role.
     */
    public function setRolePermissions(string $role, string $module, array $permissions): bool
    {
        if ($role === self::ROLE_OWNER) {
            return true; // Owner permissions cannot be restricted
        }

        $stmt = $this->db->prepare("
            INSERT INTO permissions (role, module, can_view, can_create, can_edit, can_delete)
            VALUES (:role, :module, :can_view, :can_create, :can_edit, :can_delete)
            ON DUPLICATE KEY UPDATE 
                can_view = VALUES(can_view),
                can_create = VALUES(can_create),
                can_edit = VALUES(can_edit),
                can_delete = VALUES(can_delete)
        ");

        return $stmt->execute([
            ':role' => $role,
            ':module' => $module,
            ':can_view' => (int)($permissions['can_view'] ?? 0),
            ':can_create' => (int)($permissions['can_create'] ?? 0),
            ':can_edit' => (int)($permissions['can_edit'] ?? 0),
            ':can_delete' => (int)($permissions['can_delete'] ?? 0),
        ]);
    }
}
