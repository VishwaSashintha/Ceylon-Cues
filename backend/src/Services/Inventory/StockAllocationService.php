<?php

declare(strict_types=1);

namespace CeylonCues\Services\Inventory;

use CeylonCues\Core\Database;
use PDO;

/**
 * Website Stock Allocation & Channel Visibility Service
 *
 * Allows configuring online availability independently from total physical warehouse/shop stock.
 * Prevents exposing internal inventory while safeguarding against overselling.
 */
final class StockAllocationService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get online allocation and real physical availability for a product.
     */
    public function getAllocation(int $productId, ?int $variantId = null): array
    {
        if ($variantId === null) {
            $stmt = $this->db->prepare("
                SELECT osa.*, p.name as product_name, p.sku,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE product_id = :pid) as total_physical_stock,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock s JOIN inventory_locations l ON s.location_id = l.id WHERE s.product_id = :pid2 AND l.type = 'warehouse') as warehouse_stock,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock s JOIN inventory_locations l ON s.location_id = l.id WHERE s.product_id = :pid3 AND l.type = 'shop') as shop_stock
                FROM online_stock_allocations osa
                JOIN products p ON osa.product_id = p.id
                WHERE osa.product_id = :pid4 AND osa.variant_id IS NULL
                LIMIT 1
            ");
            $stmt->execute([':pid' => $productId, ':pid2' => $productId, ':pid3' => $productId, ':pid4' => $productId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT osa.*, p.name as product_name, p.sku,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock WHERE product_id = :pid AND variant_id = :vid) as total_physical_stock,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock s JOIN inventory_locations l ON s.location_id = l.id WHERE s.product_id = :pid2 AND s.variant_id = :vid2 AND l.type = 'warehouse') as warehouse_stock,
                       (SELECT COALESCE(SUM(quantity), 0) FROM inventory_stock s JOIN inventory_locations l ON s.location_id = l.id WHERE s.product_id = :pid3 AND s.variant_id = :vid3 AND l.type = 'shop') as shop_stock
                FROM online_stock_allocations osa
                JOIN products p ON osa.product_id = p.id
                WHERE osa.product_id = :pid4 AND osa.variant_id = :vid4
                LIMIT 1
            ");
            $stmt->execute([':pid' => $productId, ':vid' => $variantId, ':pid2' => $productId, ':vid2' => $variantId, ':pid3' => $productId, ':vid3' => $variantId, ':pid4' => $productId, ':vid4' => $variantId]);
        }
        $row = $stmt->fetch();

        return $row ?: [
            'product_id' => $productId,
            'allocated_quantity' => 0,
            'is_visible_online' => 1,
            'allow_backorder' => 0,
            'total_physical_stock' => 0,
        ];
    }

    /**
     * Set or update online stock allocation.
     */
    public function setAllocation(
        int $productId,
        int $allocatedQuantity,
        bool $isVisibleOnline = true,
        bool $allowBackorder = false,
        ?int $sourceLocationId = null,
        ?int $variantId = null
    ): array {
        if ($variantId === null) {
            $stmt = $this->db->prepare("SELECT id FROM online_stock_allocations WHERE product_id = :pid AND variant_id IS NULL LIMIT 1");
            $stmt->execute([':pid' => $productId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM online_stock_allocations WHERE product_id = :pid AND variant_id = :vid LIMIT 1");
            $stmt->execute([':pid' => $productId, ':vid' => $variantId]);
        }
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $this->db->prepare("
                UPDATE online_stock_allocations
                SET allocated_quantity = :qty, is_visible_online = :vis, allow_backorder = :backorder, source_location_id = :slid, updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':qty'       => max(0, $allocatedQuantity),
                ':vis'       => $isVisibleOnline ? 1 : 0,
                ':backorder' => $allowBackorder ? 1 : 0,
                ':slid'      => $sourceLocationId,
                ':id'        => $existingId,
            ]);
        } else {
            $this->db->prepare("
                INSERT INTO online_stock_allocations (
                    product_id, variant_id, allocated_quantity, is_visible_online, allow_backorder, source_location_id
                ) VALUES (
                    :pid, :vid, :qty, :vis, :backorder, :slid
                )
            ")->execute([
                ':pid'       => $productId,
                ':vid'       => $variantId,
                ':qty'       => max(0, $allocatedQuantity),
                ':vis'       => $isVisibleOnline ? 1 : 0,
                ':backorder' => $allowBackorder ? 1 : 0,
                ':slid'      => $sourceLocationId,
            ]);
        }

        return $this->getAllocation($productId, $variantId);
    }

    /**
     * Deduct from online allocation upon confirmed online sale.
     */
    public function deductOnlineStock(int $productId, int $quantity, ?int $variantId = null): void
    {
        $this->db->prepare("
            UPDATE online_stock_allocations
            SET allocated_quantity = GREATEST(0, allocated_quantity - :qty), updated_at = NOW()
            WHERE product_id = :pid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
        ")->execute([':qty' => $quantity, ':pid' => $productId, ':vid' => $variantId, ':vid2' => $variantId]);
    }
}
