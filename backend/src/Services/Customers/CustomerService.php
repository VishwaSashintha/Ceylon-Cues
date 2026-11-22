<?php

declare(strict_types=1);

namespace CeylonCues\Services\Customers;

use CeylonCues\Core\Database;
use PDO;
use RuntimeException;

final class CustomerService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function listCustomers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = ["u.role = 'customer'", 'u.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $where[] = 'u.is_active = :active';
            $params[':active'] = (int)$filters['is_active'];
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.uuid, u.email, u.phone, u.first_name, u.last_name, u.role, u.is_active,
                u.last_login_at, u.created_at,
                COUNT(DISTINCT o.id) as total_orders,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total_amount ELSE 0 END), 0) as total_spent
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            WHERE {$whereClause}
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $customers = $stmt->fetchAll();

        foreach ($customers as &$c) {
            $c['total_orders'] = (int)$c['total_orders'];
            $c['total_spent'] = (float)$c['total_spent'];
        }

        return [
            'data'         => $customers,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
            'last_page'    => (int)ceil($total / $perPage),
        ];
    }

    public function getCustomer(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'u.id' : 'u.uuid';
        $stmt = $this->db->prepare("
            SELECT u.id, u.uuid, u.email, u.phone, u.first_name, u.last_name, u.role, u.is_active,
                   u.email_verified_at, u.last_login_at, u.created_at
            FROM users u
            WHERE {$column} = :id AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return null;
        }

        $userId = (int)$customer['id'];
        $customer['addresses'] = $this->getAddresses($userId);

        $stmtOrders = $this->db->prepare("
            SELECT id, uuid, reference_number, channel, status, payment_status, total_amount, created_at
            FROM orders
            WHERE user_id = :uid
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmtOrders->execute([':uid' => $userId]);
        $customer['recent_orders'] = $stmtOrders->fetchAll();

        $stmtStats = $this->db->prepare("
            SELECT 
                COUNT(*) as order_count,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as lifetime_spent
            FROM orders
            WHERE user_id = :uid
        ");
        $stmtStats->execute([':uid' => $userId]);
        $stats = $stmtStats->fetch();

        $customer['metrics'] = [
            'order_count'    => (int)($stats['order_count'] ?? 0),
            'lifetime_spent' => (float)($stats['lifetime_spent'] ?? 0.00),
        ];

        return $customer;
    }

    public function updateCustomer(int $id, array $data): array
    {
        $customer = $this->getCustomer($id);
        if (!$customer) {
            throw new RuntimeException("Customer with ID {$id} not found");
        }

        $fields = [];
        $params = [':id' => $id];

        $allowed = ['first_name', 'last_name', 'phone', 'is_active'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $f === 'is_active' ? (int)$data[$f] : $data[$f];
            }
        }

        if (!empty($fields)) {
            $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return $this->getCustomer($id);
    }

    public function getAddresses(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM customer_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function addAddress(int $userId, array $data): array
    {
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        if ($isDefault) {
            $this->db->prepare("UPDATE customer_addresses SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO customer_addresses (
                user_id, label, recipient_name, phone, address_line1, address_line2, city, district, postal_code, is_default
            ) VALUES (
                :uid, :label, :recip, :phone, :line1, :line2, :city, :district, :postcode, :is_def
            )
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':label'    => $data['label'] ?? 'Home',
            ':recip'    => $data['recipient_name'],
            ':phone'    => $data['phone'],
            ':line1'    => $data['address_line1'],
            ':line2'    => $data['address_line2'] ?? null,
            ':city'     => $data['city'],
            ':district' => $data['district'],
            ':postcode' => $data['postal_code'] ?? null,
            ':is_def'   => $isDefault,
        ]);

        return $this->getAddresses($userId);
    }
}
