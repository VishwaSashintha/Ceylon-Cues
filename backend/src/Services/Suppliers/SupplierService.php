<?php

declare(strict_types=1);

namespace CeylonCues\Services\Suppliers;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

final class SupplierService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function listSuppliers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(company_name LIKE :search OR contact_person LIKE :search OR email LIKE :search OR phone LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = :active';
            $params[':active'] = (int)$filters['is_active'];
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM suppliers WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT * FROM suppliers 
            WHERE {$whereClause} 
            ORDER BY company_name ASC 
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        return [
            'data'         => $items,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
            'last_page'    => (int)ceil($total / $perPage),
        ];
    }

    public function getSupplier(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'id' : 'uuid';
        $stmt = $this->db->prepare("SELECT * FROM suppliers WHERE {$column} = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $identifier]);
        $supplier = $stmt->fetch();
        return $supplier ?: null;
    }

    public function createSupplier(array $data): array
    {
        $uuid = Uuid::v4();
        $stmt = $this->db->prepare("
            INSERT INTO suppliers (
                uuid, company_name, contact_person, email, phone, address, city, country, notes, is_active
            ) VALUES (
                :uuid, :company, :contact, :email, :phone, :address, :city, :country, :notes, :active
            )
        ");
        $stmt->execute([
            ':uuid'    => $uuid,
            ':company' => trim($data['company_name']),
            ':contact' => $data['contact_person'] ?? null,
            ':email'   => $data['email'] ?? null,
            ':phone'   => $data['phone'] ?? null,
            ':address' => $data['address'] ?? null,
            ':city'    => $data['city'] ?? null,
            ':country' => $data['country'] ?? 'Sri Lanka',
            ':notes'   => $data['notes'] ?? null,
            ':active'  => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);

        return $this->getSupplier((int)$this->db->lastInsertId());
    }

    public function updateSupplier(int $id, array $data): array
    {
        $supplier = $this->getSupplier($id);
        if (!$supplier) {
            throw new RuntimeException("Supplier with ID {$id} not found");
        }

        $fields = [];
        $params = [':id' => $id];

        $allowed = ['company_name', 'contact_person', 'email', 'phone', 'address', 'city', 'country', 'notes', 'is_active'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $f === 'is_active' ? (int)$data[$f] : $data[$f];
            }
        }

        if (empty($fields)) {
            return $supplier;
        }

        $sql = "UPDATE suppliers SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->getSupplier($id);
    }

    public function deleteSupplier(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE suppliers SET deleted_at = NOW(), is_active = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getSupplierPurchaseHistory(int $supplierId): array
    {
        $stmt = $this->db->prepare("
            SELECT po.*, u.first_name as created_by_name
            FROM purchase_orders po
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.supplier_id = :sid
            ORDER BY po.created_at DESC
        ");
        $stmt->execute([':sid' => $supplierId]);
        return $stmt->fetchAll();
    }
}
