<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Cms;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Database;
use CeylonCues\Core\Validator;
use PDO;

/**
 * Homepage Banner Management Controller
 *
 * Public:  GET  /api/v1/banners               — list active banners for website
 * ERP:     GET  /api/v1/erp/banners           — list all banners (admin)
 *          POST /api/v1/erp/banners           — create banner
 *          PUT  /api/v1/erp/banners/{id}      — update banner
 *          DELETE /api/v1/erp/banners/{id}    — delete banner
 *          PATCH /api/v1/erp/banners/{id}/toggle — toggle active state
 */
final class BannerController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/v1/banners — public, only active banners */
    public function listPublic(Request $request): void
    {
        $stmt = $this->db->query("
            SELECT id, title, subtitle, image_url, link_url, sort_order
            FROM cms_banners
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        Response::json($stmt->fetchAll(PDO::FETCH_ASSOC), 'Banners retrieved');
    }

    /** GET /api/v1/erp/banners — ERP admin: all banners */
    public function listAll(Request $request): void
    {
        $stmt = $this->db->query("SELECT * FROM cms_banners ORDER BY sort_order ASC, id ASC");
        Response::json($stmt->fetchAll(PDO::FETCH_ASSOC), 'All banners retrieved');
    }

    /** POST /api/v1/erp/banners */
    public function create(Request $request): void
    {
        $data = $request->getBody();
        $v    = Validator::validate($data, [
            'title'     => 'required|string|max:255',
            'image_url' => 'required|string|max:255',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $stmt = $this->db->prepare("
            INSERT INTO cms_banners (title, subtitle, image_url, link_url, sort_order, is_active)
            VALUES (:title, :subtitle, :image_url, :link_url, :sort_order, :is_active)
        ");
        $stmt->execute([
            ':title'      => $data['title'],
            ':subtitle'   => $data['subtitle'] ?? null,
            ':image_url'  => $data['image_url'],
            ':link_url'   => $data['link_url'] ?? null,
            ':sort_order' => (int)($data['sort_order'] ?? 0),
            ':is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);

        $id = (int)$this->db->lastInsertId();
        Response::created(['id' => $id], 'Banner created');
    }

    /** PUT /api/v1/erp/banners/{id} */
    public function update(Request $request): void
    {
        $id   = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $existing = $this->db->prepare("SELECT id FROM cms_banners WHERE id = :id");
        $existing->execute([':id' => $id]);
        if (!$existing->fetch()) {
            Response::notFound('Banner not found');
        }

        $this->db->prepare("
            UPDATE cms_banners
            SET title      = :title,
                subtitle   = :subtitle,
                image_url  = :image_url,
                link_url   = :link_url,
                sort_order = :sort_order,
                is_active  = :is_active
            WHERE id = :id
        ")->execute([
            ':title'      => $data['title'] ?? '',
            ':subtitle'   => $data['subtitle'] ?? null,
            ':image_url'  => $data['image_url'] ?? '',
            ':link_url'   => $data['link_url'] ?? null,
            ':sort_order' => (int)($data['sort_order'] ?? 0),
            ':is_active'  => (int)(bool)($data['is_active'] ?? true),
            ':id'         => $id,
        ]);

        Response::json(['id' => $id], 'Banner updated');
    }

    /** DELETE /api/v1/erp/banners/{id} */
    public function delete(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->db->prepare("DELETE FROM cms_banners WHERE id = :id")->execute([':id' => $id]);
        Response::json(['id' => $id], 'Banner deleted');
    }

    /** PATCH /api/v1/erp/banners/{id}/toggle */
    public function toggle(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->db->prepare("
            UPDATE cms_banners SET is_active = NOT is_active WHERE id = :id
        ")->execute([':id' => $id]);

        $row = $this->db->prepare("SELECT is_active FROM cms_banners WHERE id = :id");
        $row->execute([':id' => $id]);
        $banner = $row->fetch(PDO::FETCH_ASSOC);

        Response::json(['id' => $id, 'is_active' => (bool)($banner['is_active'] ?? false)], 'Banner toggled');
    }
}
