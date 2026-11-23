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

    public function exportCustomerData(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, email, phone, first_name, last_name, role, is_active,
                   email_verified_at, last_login_at, created_at
            FROM users WHERE id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $customerId]);
        $profile = $stmt->fetch();

        if (!$profile) {
            throw new RuntimeException("Customer #{$customerId} not found.");
        }

        $addresses = $this->db->prepare("SELECT * FROM customer_addresses WHERE user_id = :uid")
            ->execute([':uid' => $customerId]) ? null : null;
        $addrStmt = $this->db->prepare("SELECT * FROM customer_addresses WHERE user_id = :uid");
        $addrStmt->execute([':uid' => $customerId]);

        $ordersStmt = $this->db->prepare("
            SELECT o.*, GROUP_CONCAT(oi.product_name_snapshot ORDER BY oi.id SEPARATOR ', ') as items_summary
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.user_id = :uid
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");
        $ordersStmt->execute([':uid' => $customerId]);

        $ticketsStmt = $this->db->prepare("SELECT * FROM support_tickets WHERE user_id = :uid ORDER BY created_at DESC");
        $ticketsStmt->execute([':uid' => $customerId]);

        $notifStmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 100");
        $notifStmt->execute([':uid' => $customerId]);

        $loginStmt = $this->db->prepare("SELECT * FROM login_attempts WHERE email = :email ORDER BY created_at DESC LIMIT 50");
        $loginStmt->execute([':email' => $profile['email']]);

        return [
            'profile'        => $profile,
            'addresses'      => $addrStmt->fetchAll(),
            'orders'         => $ordersStmt->fetchAll(),
            'support_tickets' => $ticketsStmt->fetchAll(),
            'notifications'  => $notifStmt->fetchAll(),
            'login_attempts' => $loginStmt->fetchAll(),
            'exported_at'    => date('Y-m-d H:i:s'),
        ];
    }

    public function eraseCustomerData(int $customerId, int $requestedByUserId): void
    {
        if ($customerId !== $requestedByUserId) {
            $stmt = $this->db->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $requestedByUserId]);
            $requester = $stmt->fetch();
            if (!$requester || !in_array($requester['role'], ['admin', 'super_admin'], true)) {
                throw new RuntimeException('Only the account owner or an administrator may erase customer data.');
            }
        }

        $customer = $this->db->prepare("SELECT id, email FROM users WHERE id = :id LIMIT 1");
        $customer->execute([':id' => $customerId]);
        $user = $customer->fetch();

        if (!$user) {
            throw new RuntimeException("Customer #{$customerId} not found.");
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE users
                SET first_name   = '[Deleted]',
                    last_name    = '[Deleted]',
                    email        = CONCAT('deleted_', id, '@removed.invalid'),
                    phone        = '[Deleted]',
                    is_active    = 0,
                    updated_at   = NOW()
                WHERE id = :id
            ")->execute([':id' => $customerId]);

            $this->db->prepare("DELETE FROM customer_addresses WHERE user_id = :uid")->execute([':uid' => $customerId]);

            $this->db->prepare("DELETE FROM carts WHERE user_id = :uid")->execute([':uid' => $customerId]);

            $this->db->prepare("DELETE FROM sessions WHERE user_id = :uid")->execute([':uid' => $customerId]);

            // Anonymise order PII while preserving financial totals and invoice numbers
            $this->db->prepare("
                UPDATE orders
                SET customer_name  = '[Anonymised]',
                    customer_phone = '[Anonymised]',
                    updated_at     = NOW()
                WHERE user_id = :uid
            ")->execute([':uid' => $customerId]);

            $this->db->prepare("
                INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
                VALUES
                    (:by, 'customer_data_erased', 'users', :eid, :old, :new, :ip, :ua)
            ")->execute([
                ':by'  => $requestedByUserId,
                ':eid' => $customerId,
                ':old' => json_encode(['email' => $user['email']], JSON_UNESCAPED_UNICODE),
                ':new' => json_encode(['email' => 'deleted_' . $customerId . '@removed.invalid', 'anonymised' => true]),
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 500),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
