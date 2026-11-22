<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Auth;

use CeylonCues\Core\Database;
use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Uuid;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Auth\AuditService;
use CeylonCues\Services\Auth\RbacService;
use PDO;

/**
 * Employee Management Controller (Owner Only)
 */
final class EmployeeController
{
    private PDO $db;
    private AuditService $audit;
    private RbacService $rbac;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->audit = new AuditService();
        $this->rbac = new RbacService();
    }

    /**
     * GET /api/v1/employees
     */
    public function index(Request $request): void
    {
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $total = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role != 'customer'")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT id, uuid, email, phone, first_name, last_name, role, is_active, is_owner, last_login_at, created_at
            FROM users
            WHERE role != 'customer'
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $employees = $stmt->fetchAll();

        Response::paginated($employees, $total, $page, $perPage, 'Employees retrieved successfully');
    }

    /**
     * POST /api/v1/employees
     */
    public function store(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|max:255',
            'phone'      => 'required|string|max:20',
            'password'   => 'required|string|min:8',
            'role'       => 'required|in:shop_manager,shop_cashier,warehouse_manager,inventory_staff,order_staff,customer_support,finance_staff',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        // Check duplicate email
        $check = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $check->execute([':email' => strtolower(trim($data['email']))]);
        if ($check->fetch()) {
            Response::error('Email address is already in use', 400);
        }

        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $uuid = Uuid::v4();
        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, email, phone, password_hash, first_name, last_name, role, is_active, is_owner, created_at, updated_at)
            VALUES (:uuid, :email, :phone, :password_hash, :first_name, :last_name, :role, 1, 0, NOW(), NOW())
        ");

        $stmt->execute([
            ':uuid'          => $uuid,
            ':email'         => strtolower(trim($data['email'])),
            ':phone'         => trim($data['phone']),
            ':password_hash' => $passwordHash,
            ':first_name'    => trim($data['first_name']),
            ':last_name'     => trim($data['last_name']),
            ':role'          => $data['role'],
        ]);

        $newId = (int)$this->db->lastInsertId();

        $currentUser = $request->getUser();
        $this->audit->log('create_employee', 'users', $newId, null, [
            'email' => $data['email'],
            'role' => $data['role']
        ], (int)($currentUser['id'] ?? null));

        $stmtUser = $this->db->prepare("SELECT id, uuid, email, phone, first_name, last_name, role, is_active, created_at FROM users WHERE id = :id");
        $stmtUser->execute([':id' => $newId]);
        $created = $stmtUser->fetch();

        Response::created($created, 'Employee account created successfully');
    }

    /**
     * GET /api/v1/employees/{id}
     */
    public function show(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $stmt = $this->db->prepare("SELECT id, uuid, email, phone, first_name, last_name, role, is_active, is_owner, last_login_at, created_at FROM users WHERE id = :id AND role != 'customer'");
        $stmt->execute([':id' => $id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            Response::notFound('Employee not found');
        }

        $employee['permissions'] = $this->rbac->getRolePermissions($employee['role']);
        Response::json($employee, 'Employee retrieved');
    }

    /**
     * PUT /api/v1/employees/{id}
     */
    public function update(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id AND role != 'customer'");
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::notFound('Employee not found');
        }

        if (!empty($existing['is_owner'])) {
            Response::error('Super Administrator settings can only be altered through Owner Settings', 403);
        }

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['first_name'])) {
            $fields[] = 'first_name = :first_name';
            $params[':first_name'] = trim($data['first_name']);
        }
        if (isset($data['last_name'])) {
            $fields[] = 'last_name = :last_name';
            $params[':last_name'] = trim($data['last_name']);
        }
        if (isset($data['phone'])) {
            $fields[] = 'phone = :phone';
            $params[':phone'] = trim($data['phone']);
        }
        if (isset($data['role'])) {
            $validRoles = ['shop_manager', 'shop_cashier', 'warehouse_manager', 'inventory_staff', 'order_staff', 'customer_support', 'finance_staff'];
            if (!in_array($data['role'], $validRoles, true)) {
                Response::error('Invalid employee role', 400);
            }
            $fields[] = 'role = :role';
            $params[':role'] = $data['role'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = (int)$data['is_active'];
        }
        if (!empty($data['password'])) {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($fields)) {
            Response::error('No fields provided to update', 400);
        }

        $fieldsSql = implode(', ', $fields);
        $this->db->prepare("UPDATE users SET {$fieldsSql}, updated_at = NOW() WHERE id = :id")->execute($params);

        $currentUser = $request->getUser();
        $this->audit->log('update_employee', 'users', $id, $existing, $data, (int)($currentUser['id'] ?? null));

        Response::json(null, 'Employee updated successfully');
    }

    /**
     * DELETE /api/v1/employees/{id} (Deactivate/Soft Delete)
     */
    public function destroy(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');

        $stmt = $this->db->prepare("SELECT id, is_owner FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('Employee not found');
        }

        if (!empty($user['is_owner'])) {
            Response::error('Cannot deactivate the Super Administrator / Business Owner', 403);
        }

        $this->db->prepare("UPDATE users SET is_active = 0, deleted_at = NOW() WHERE id = :id")->execute([':id' => $id]);

        $currentUser = $request->getUser();
        $this->audit->log('deactivate_employee', 'users', $id, null, null, (int)($currentUser['id'] ?? null));

        Response::json(null, 'Employee account deactivated');
    }

    /**
     * PUT /api/v1/employees/permissions
     */
    public function updatePermissions(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'role'   => 'required|string',
            'module' => 'required|string',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $this->rbac->setRolePermissions($data['role'], $data['module'], [
            'can_view'   => $data['can_view'] ?? 0,
            'can_create' => $data['can_create'] ?? 0,
            'can_edit'   => $data['can_edit'] ?? 0,
            'can_delete' => $data['can_delete'] ?? 0,
        ]);

        Response::json(null, 'Permissions updated successfully');
    }
}
