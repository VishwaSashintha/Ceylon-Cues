<?php

declare(strict_types=1);

namespace CeylonCues\Services\Settings;

use CeylonCues\Core\Database;
use PDO;
use RuntimeException;

final class CmsService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function listPages(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $this->db->query("
            SELECT id, title, slug, is_active, created_at, updated_at
            FROM cms_pages {$where}
            ORDER BY title ASC
        ")->fetchAll();
    }

    public function getPageBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cms_pages WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $page = $stmt->fetch();
        return $page ?: null;
    }

    public function createPage(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO cms_pages (title, slug, content, is_active, created_at, updated_at)
            VALUES (:title, :slug, :content, :active, NOW(), NOW())
        ");
        $stmt->execute([
            ':title'   => $data['title'],
            ':slug'    => $data['slug'],
            ':content' => $data['content'] ?? '',
            ':active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->getPageById($id);
    }

    public function updatePage(int $id, array $data): array
    {
        $page = $this->getPageById($id);
        if (!$page) {
            throw new RuntimeException('CMS page not found');
        }

        $stmt = $this->db->prepare("
            UPDATE cms_pages
            SET title = :title, slug = :slug, content = :content, is_active = :active, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':title'   => $data['title']     ?? $page['title'],
            ':slug'    => $data['slug']      ?? $page['slug'],
            ':content' => $data['content']   ?? $page['content'],
            ':active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : $page['is_active'],
            ':id'      => $id,
        ]);

        return $this->getPageById($id);
    }

    public function deletePage(int $id): void
    {
        $this->db->prepare("DELETE FROM cms_pages WHERE id = :id")->execute([':id' => $id]);
    }

    private function getPageById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cms_pages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $page = $stmt->fetch();
        return $page ?: null;
    }
}
