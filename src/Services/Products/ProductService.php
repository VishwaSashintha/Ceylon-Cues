<?php

declare(strict_types=1);

namespace CeylonCues\Services\Products;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Product Management Service
 */
final class ProductService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Search and list products with filtering and pagination.
     */
    public function listProducts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :cat_id';
            $params[':cat_id'] = $filters['category_id'];
        }
        if (!empty($filters['quality_tier'])) {
            $where[] = 'p.quality_tier = :quality_tier';
            $params[':quality_tier'] = $filters['quality_tier'];
        }
        if (isset($filters['is_active'])) {
            $where[] = 'p.is_active = :is_active';
            $params[':is_active'] = (int)$filters['is_active'];
        }
        if (isset($filters['is_published'])) {
            $where[] = 'p.is_published = :is_published';
            $params[':is_published'] = (int)$filters['is_published'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search OR p.description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT p.*, c.name as category_name, s.company_name as supplier_name,
                   COALESCE(osa.allocated_quantity, 0) as online_stock,
                   COALESCE(osa.is_visible_online, 1) as is_visible_online,
                   (SELECT SUM(quantity) FROM inventory_stock WHERE product_id = p.id) as total_physical_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN online_stock_allocations osa ON p.id = osa.product_id
            WHERE {$whereSql}
            ORDER BY p.is_featured DESC, p.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['specifications'] = json_decode($item['specifications'] ?? '[]', true);
            $item['images'] = $this->getProductImages((int)$item['id']);
            $item['variants'] = $this->getProductVariants((int)$item['id']);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get single product by ID or Slug.
     */
    public function getProduct(int|string $identifier): ?array
    {
        $col = is_numeric($identifier) ? 'p.id' : 'p.slug';
        $stmt = $this->db->prepare("
            SELECT p.*, c.name as category_name, s.company_name as supplier_name,
                   COALESCE(osa.allocated_quantity, 0) as online_stock,
                   COALESCE(osa.is_visible_online, 1) as is_visible_online
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN online_stock_allocations osa ON p.id = osa.product_id
            WHERE {$col} = :id AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $prod = $stmt->fetch();

        if (!$prod) {
            return null;
        }

        $prod['specifications'] = json_decode($prod['specifications'] ?? '[]', true);
        $prod['images'] = $this->getProductImages((int)$prod['id']);
        $prod['variants'] = $this->getProductVariants((int)$prod['id']);
        $prod['location_stocks'] = $this->getProductStockPerLocation((int)$prod['id']);

        return $prod;
    }

    /**
     * Create product with optional initial stock and online allocation.
     */
    public function createProduct(array $data, ?int $userId = null): array
    {
        $uuid = Uuid::v4();
        $slug = $data['slug'] ?? $this->generateSlug($data['name']);

        $stmt = $this->db->prepare("
            INSERT INTO products (
                uuid, sku, barcode, name, slug, category_id, supplier_id,
                description, specifications, quality_tier, cost_price, retail_price,
                wholesale_price, weight_kg, is_active, is_published, is_featured, min_wholesale_qty
            ) VALUES (
                :uuid, :sku, :barcode, :name, :slug, :category_id, :supplier_id,
                :description, :specifications, :quality_tier, :cost_price, :retail_price,
                :wholesale_price, :weight_kg, :is_active, :is_published, :is_featured, :min_wholesale_qty
            )
        ");

        $stmt->execute([
            ':uuid'              => $uuid,
            ':sku'               => strtoupper(trim($data['sku'])),
            ':barcode'           => !empty($data['barcode']) ? trim($data['barcode']) : null,
            ':name'              => trim($data['name']),
            ':slug'              => $slug,
            ':category_id'       => (int)$data['category_id'],
            ':supplier_id'       => !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            ':description'       => $data['description'] ?? null,
            ':specifications'    => isset($data['specifications']) ? json_encode($data['specifications'], JSON_UNESCAPED_UNICODE) : null,
            ':quality_tier'      => $data['quality_tier'] ?? 'standard',
            ':cost_price'        => $data['cost_price'] ?? 0.00,
            ':retail_price'      => $data['retail_price'],
            ':wholesale_price'   => $data['wholesale_price'] ?? null,
            ':weight_kg'         => $data['weight_kg'] ?? null,
            ':is_active'         => (int)($data['is_active'] ?? 1),
            ':is_published'      => (int)($data['is_published'] ?? 1),
            ':is_featured'       => (int)($data['is_featured'] ?? 0),
            ':min_wholesale_qty' => (int)($data['min_wholesale_qty'] ?? 1),
        ]);

        $productId = (int)$this->db->lastInsertId();

        // Default online allocation
        $allocQty = (int)($data['online_stock_allocation'] ?? 0);
        $this->db->prepare("DELETE FROM online_stock_allocations WHERE product_id = :pid AND variant_id IS NULL")->execute([':pid' => $productId]);
        $this->db->prepare("
            INSERT INTO online_stock_allocations (product_id, allocated_quantity, is_visible_online)
            VALUES (:pid, :qty, :vis)
        ")->execute([':pid' => $productId, ':qty' => $allocQty, ':vis' => 1]);

        return $this->getProduct($productId);
    }

    /**
     * Update product details and track cost change history.
     */
    public function updateProduct(int $id, array $data, ?int $userId = null): array
    {
        $existing = $this->getProduct($id);
        if (!$existing) {
            throw new RuntimeException('Product not found');
        }

        // Track cost change history
        if (isset($data['cost_price']) && (float)$data['cost_price'] !== (float)$existing['cost_price']) {
            $this->db->prepare("
                INSERT INTO product_cost_history (product_id, old_cost, new_cost, changed_by, reason)
                VALUES (:pid, :old, :new, :by, :reason)
            ")->execute([
                ':pid' => $id,
                ':old' => $existing['cost_price'],
                ':new' => $data['cost_price'],
                ':by'  => $userId,
                ':reason' => $data['cost_change_reason'] ?? 'Cost update via ERP',
            ]);
        }

        $fields = [];
        $params = [':id' => $id];

        $allowedFields = [
            'name', 'category_id', 'supplier_id', 'description', 'quality_tier',
            'cost_price', 'retail_price', 'wholesale_price', 'weight_kg',
            'is_active', 'is_published', 'is_featured', 'min_wholesale_qty', 'barcode'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (isset($data['specifications'])) {
            $fields[] = "specifications = :specs";
            $params[':specs'] = json_encode($data['specifications'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($fields)) {
            $sql = "UPDATE products SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }

        // Update online allocation if requested
        if (isset($data['online_stock_allocation'])) {
            $this->db->prepare("
                INSERT INTO online_stock_allocations (product_id, allocated_quantity, is_visible_online)
                VALUES (:pid, :qty, 1)
                ON DUPLICATE KEY UPDATE allocated_quantity = VALUES(allocated_quantity)
            ")->execute([':pid' => $id, ':qty' => (int)$data['online_stock_allocation']]);
        }

        return $this->getProduct($id);
    }

    /**
     * Soft delete product.
     */
    public function deleteProduct(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE products SET deleted_at = NOW(), is_active = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getProductImages(int $productId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM product_images WHERE product_id = :pid ORDER BY is_primary DESC, sort_order ASC");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function getProductVariants(int $productId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM product_variants WHERE product_id = :pid AND is_active = 1 ORDER BY sort_order ASC");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function getProductStockPerLocation(int $productId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, l.name as location_name, l.type as location_type
            FROM inventory_stock s
            JOIN inventory_locations l ON s.location_id = l.id
            WHERE s.product_id = :pid
        ");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        $test = $slug;
        $i = 1;
        while ($this->db->query("SELECT id FROM products WHERE slug = '{$test}' LIMIT 1")->fetch()) {
            $test = "{$slug}-{$i}";
            $i++;
        }
        return $test;
    }
}
