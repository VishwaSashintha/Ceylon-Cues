<?php

declare(strict_types=1);

namespace CeylonCues\Services\Products;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Category Management Service
 */
final class CategoryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function appendImageUrl(array &$category): void
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/ceylon-cues', '/');
        $path = $category['image_path'] ?? '';
        $category['image_url'] = (!empty($path) && !str_starts_with($path, 'http'))
            ? $appUrl . '/' . ltrim($path, '/')
            : $path;
    }

    public function listCategories(bool $tree = false): array
    {
        $stmt = $this->db->query("
            SELECT c.*, 
                   (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as product_count
            FROM categories c
            WHERE c.deleted_at IS NULL
            ORDER BY c.sort_order ASC, c.name ASC
        ");
        $categories = $stmt->fetchAll();
        foreach ($categories as &$cat) {
            $this->appendImageUrl($cat);
        }

        if (!$tree) {
            return $categories;
        }

        // Build hierarchical tree
        $treeList = [];
        $map = [];
        foreach ($categories as $cat) {
            $cat['children'] = [];
            $map[$cat['id']] = $cat;
        }

        foreach ($map as $id => &$cat) {
            if ($cat['parent_id'] && isset($map[$cat['parent_id']])) {
                $map[$cat['parent_id']]['children'][] = &$cat;
            } else {
                $treeList[] = &$cat;
            }
        }

        return $treeList;
    }

    public function getCategory(int|string $identifier): ?array
    {
        $col = is_numeric($identifier) ? 'id' : 'slug';
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE {$col} = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':id' => $identifier]);
        $cat = $stmt->fetch();
        if ($cat) {
            $this->appendImageUrl($cat);
        }
        return $cat ?: null;
    }

    public function createCategory(array $data): array
    {
        $uuid = Uuid::v4();
        $slug = $data['slug'] ?? strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']), '-'));

        $stmt = $this->db->prepare("
            INSERT INTO categories (uuid, parent_id, name, slug, description, image_path, sort_order, is_active)
            VALUES (:uuid, :parent_id, :name, :slug, :description, :image_path, :sort_order, :is_active)
        ");

        $stmt->execute([
            ':uuid'        => $uuid,
            ':parent_id'   => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            ':name'        => trim($data['name']),
            ':slug'        => $slug,
            ':description' => $data['description'] ?? null,
            ':image_path'  => $data['image_path'] ?? null,
            ':sort_order'  => (int)($data['sort_order'] ?? 0),
            ':is_active'   => (int)($data['is_active'] ?? 1),
        ]);

        return $this->getCategory((int)$this->db->lastInsertId());
    }

    public function updateCategory(int $id, array $data): array
    {
        $existing = $this->getCategory($id);
        if (!$existing) {
            throw new RuntimeException('Category not found');
        }

        $fields = [];
        $params = [':id' => $id];

        foreach (['name', 'parent_id', 'description', 'image_path', 'sort_order', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f];
            }
        }

        if (!empty($fields)) {
            $sql = "UPDATE categories SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
        }

        return $this->getCategory($id);
    }

    public function deleteCategory(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE categories SET deleted_at = NOW(), is_active = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
