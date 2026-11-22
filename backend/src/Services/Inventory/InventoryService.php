<?php

declare(strict_types=1);

namespace CeylonCues\Services\Inventory;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Inventory & Stock Engine
 *
 * Enforces location-specific balances, transaction safety, ledger movements,
 * stock adjustments, and prevents overselling.
 */
final class InventoryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get inventory locations list.
     */
    public function getLocations(): array
    {
        $stmt = $this->db->query("SELECT * FROM inventory_locations WHERE is_active = 1 ORDER BY is_default DESC, name ASC");
        return $stmt->fetchAll();
    }

    /**
     * Get real-time stock balances across all locations or for a specific location.
     */
    public function getStockLevels(?int $locationId = null, array $filters = []): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if ($locationId !== null) {
            $where[] = 's.location_id = :loc_id';
            $params[':loc_id'] = $locationId;
        }

        if (!empty($filters['search'])) {
            $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['low_stock_only'])) {
            $where[] = 's.quantity <= s.reorder_level';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT s.*, p.name as product_name, p.sku, p.cost_price, p.retail_price,
                   l.name as location_name, l.type as location_type,
                   (s.quantity - s.reserved_quantity) as available_quantity,
                   (s.quantity * p.cost_price) as stock_valuation
            FROM inventory_stock s
            JOIN products p ON s.product_id = p.id
            JOIN inventory_locations l ON s.location_id = l.id
            WHERE {$whereSql}
            ORDER BY s.quantity ASC, p.name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Adjust stock quantity directly with ledger audit.
     */
    public function adjustStock(
        int $productId,
        int $locationId,
        int $quantityChange,
        string $reason,
        ?string $notes = null,
        ?int $userId = null,
        ?int $variantId = null
    ): array {
        $this->db->beginTransaction();
        try {
            // Lock row for update
            $stmt = $this->db->prepare("
                SELECT id, quantity FROM inventory_stock
                WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                FOR UPDATE
            ");
            $stmt->execute([':pid' => $productId, ':lid' => $locationId, ':vid' => $variantId, ':vid2' => $variantId]);
            $stock = $stmt->fetch();

            $qtyBefore = $stock ? (int)$stock['quantity'] : 0;
            $qtyAfter = $qtyBefore + $quantityChange;

            if ($qtyAfter < 0) {
                throw new RuntimeException("Stock cannot be adjusted below zero. Current: {$qtyBefore}, Attempted change: {$quantityChange}");
            }

            if ($stock) {
                $this->db->prepare("UPDATE inventory_stock SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                    ->execute([':qty' => $qtyAfter, ':id' => $stock['id']]);
            } else {
                $this->db->prepare("
                    INSERT INTO inventory_stock (product_id, variant_id, location_id, quantity)
                    VALUES (:pid, :vid, :lid, :qty)
                ")->execute([':pid' => $productId, ':vid' => $variantId, ':lid' => $locationId, ':qty' => $qtyAfter]);
            }

            // Record movement in ledger
            $movementType = $quantityChange > 0 ? 'adjustment' : ($reason === 'damaged' ? 'damaged' : 'adjustment');
            $costPrice = (float)$this->db->query("SELECT cost_price FROM products WHERE id = {$productId}")->fetchColumn();

            $this->db->prepare("
                INSERT INTO stock_movements (
                    product_id, variant_id, location_id, movement_type,
                    quantity, quantity_before, quantity_after, reference_type, unit_cost, notes, created_by
                ) VALUES (
                    :pid, :vid, :lid, :type,
                    :qty, :before, :after, :ref_type, :cost, :notes, :by
                )
            ")->execute([
                ':pid'      => $productId,
                ':vid'      => $variantId,
                ':lid'      => $locationId,
                ':type'     => $movementType,
                ':qty'      => abs($quantityChange),
                ':before'   => $qtyBefore,
                ':after'    => $qtyAfter,
                ':ref_type' => 'manual_adjustment',
                ':cost'     => $costPrice,
                ':notes'    => "Reason: {$reason}. Notes: {$notes}",
                ':by'       => $userId,
            ]);

            $this->db->commit();

            return [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity_before' => $qtyBefore,
                'quantity_change' => $quantityChange,
                'quantity_after' => $qtyAfter,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reserve stock for pending orders (prevents double selling).
     */
    public function reserveStock(int $productId, int $locationId, int $quantity, ?int $variantId = null): bool
    {
        $stmt = $this->db->prepare("
            SELECT id, quantity, reserved_quantity FROM inventory_stock
            WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
            FOR UPDATE
        ");
        $stmt->execute([':pid' => $productId, ':lid' => $locationId, ':vid' => $variantId, ':vid2' => $variantId]);
        $stock = $stmt->fetch();

        if (!$stock) {
            return false;
        }

        $available = (int)$stock['quantity'] - (int)$stock['reserved_quantity'];
        if ($available < $quantity) {
            return false;
        }

        $this->db->prepare("UPDATE inventory_stock SET reserved_quantity = reserved_quantity + :qty WHERE id = :id")
            ->execute([':qty' => $quantity, ':id' => $stock['id']]);

        return true;
    }

    /**
     * Release reserved stock (e.g. order cancelled or expired).
     */
    public function releaseReservedStock(int $productId, int $locationId, int $quantity, ?int $variantId = null): void
    {
        $this->db->prepare("
            UPDATE inventory_stock 
            SET reserved_quantity = GREATEST(0, reserved_quantity - :qty)
            WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
        ")->execute([':qty' => $quantity, ':pid' => $productId, ':lid' => $locationId, ':vid' => $variantId, ':vid2' => $variantId]);
    }

    /**
     * Get stock movement audit ledger for a product or location.
     */
    public function getMovements(array $filters = [], int $limit = 50): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['product_id'])) {
            $where[] = 'm.product_id = :pid';
            $params[':pid'] = $filters['product_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = 'm.location_id = :lid';
            $params[':lid'] = $filters['location_id'];
        }
        if (!empty($filters['movement_type'])) {
            $where[] = 'm.movement_type = :mtype';
            $params[':mtype'] = $filters['movement_type'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT m.*, p.name as product_name, p.sku, l.name as location_name, u.first_name, u.last_name
            FROM stock_movements m
            JOIN products p ON m.product_id = p.id
            JOIN inventory_locations l ON m.location_id = l.id
            LEFT JOIN users u ON m.created_by = u.id
            WHERE {$whereSql}
            ORDER BY m.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function createStockCount(int $locationId, int $userId, ?string $notes = null): array
    {
        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM stock_counts WHERE reference_number LIKE 'CC-SC-{$year}-%'")->fetchColumn() + 1;
        $ref = sprintf('CC-SC-%s-%04d', $year, $count);
        $uuid = \CeylonCues\Core\Uuid::v4();

        $stmt = $this->db->prepare("
            INSERT INTO stock_counts (uuid, reference_number, location_id, status, counted_by, notes)
            VALUES (:uuid, :ref, :loc_id, 'in_progress', :user_id, :notes)
        ");
        $stmt->execute([
            ':uuid'    => $uuid,
            ':ref'     => $ref,
            ':loc_id'  => $locationId,
            ':user_id' => $userId,
            ':notes'   => $notes,
        ]);

        return $this->getStockCount((int)$this->db->lastInsertId());
    }

    public function listStockCounts(?int $locationId = null): array
    {
        $where = $locationId ? "WHERE sc.location_id = {$locationId}" : "";
        $stmt = $this->db->query("
            SELECT sc.*, l.name as location_name, u.first_name as counted_by_name
            FROM stock_counts sc
            JOIN inventory_locations l ON sc.location_id = l.id
            JOIN users u ON sc.counted_by = u.id
            {$where}
            ORDER BY sc.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    public function getStockCount(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'sc.id' : 'sc.reference_number';
        $stmt = $this->db->prepare("
            SELECT sc.*, l.name as location_name, u.first_name as counted_by_name
            FROM stock_counts sc
            JOIN inventory_locations l ON sc.location_id = l.id
            JOIN users u ON sc.counted_by = u.id
            WHERE {$column} = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $count = $stmt->fetch();
        if (!$count) {
            return null;
        }

        $stmtItems = $this->db->prepare("
            SELECT sci.*, p.name as product_name, p.sku
            FROM stock_count_items sci
            JOIN products p ON sci.product_id = p.id
            WHERE sci.count_id = :cid
        ");
        $stmtItems->execute([':cid' => $count['id']]);
        $count['items'] = $stmtItems->fetchAll();

        return $count;
    }

    public function recordCountItem(int $countId, int $productId, int $countedQuantity, ?int $variantId = null, ?string $notes = null): array
    {
        $count = $this->getStockCount($countId);
        if (!$count || $count['status'] !== 'in_progress') {
            throw new RuntimeException('Active in-progress stock count required');
        }

        $stmtSys = $this->db->prepare("
            SELECT COALESCE(quantity, 0) FROM inventory_stock 
            WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
        ");
        $stmtSys->execute([':pid' => $productId, ':lid' => $count['location_id'], ':vid' => $variantId, ':vid2' => $variantId]);
        $sysQty = (int)$stmtSys->fetchColumn();

        $variance = $countedQuantity - $sysQty;

        $stmtItem = $this->db->prepare("
            INSERT INTO stock_count_items (
                count_id, product_id, variant_id, system_quantity, counted_quantity, variance, notes
            ) VALUES (
                :cid, :pid, :vid, :sys_qty, :count_qty, :variance, :notes
            )
        ");
        $stmtItem->execute([
            ':cid'       => $countId,
            ':pid'       => $productId,
            ':vid'       => $variantId,
            ':sys_qty'   => $sysQty,
            ':count_qty' => $countedQuantity,
            ':variance'  => $variance,
            ':notes'     => $notes,
        ]);

        return $this->getStockCount($countId);
    }

    public function reconcileStockCount(int $countId, int $reconciledBy): array
    {
        $count = $this->getStockCount($countId);
        if (!$count || $count['status'] !== 'in_progress') {
            throw new RuntimeException('Stock count not found or already reconciled');
        }

        $this->db->beginTransaction();
        try {
            $stmtMovement = $this->db->prepare("
                INSERT INTO stock_movements (
                    product_id, variant_id, location_id, movement_type,
                    quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by
                ) VALUES (
                    :pid, :vid, :lid, 'count_correction',
                    :qty, :before, :after, 'stock_count', :ref_id, :notes, :by
                )
            ");

            foreach ($count['items'] as $item) {
                if ((int)$item['variance'] !== 0) {
                    $pid = (int)$item['product_id'];
                    $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                    $sysQty = (int)$item['system_quantity'];
                    $countedQty = (int)$item['counted_quantity'];

                    $stmtCheck = $this->db->prepare("
                        SELECT id FROM inventory_stock
                        WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                        FOR UPDATE
                    ");
                    $stmtCheck->execute([':pid' => $pid, ':lid' => $count['location_id'], ':vid' => $vid, ':vid2' => $vid]);
                    $stockRow = $stmtCheck->fetch();

                    if ($stockRow) {
                        $this->db->prepare("UPDATE inventory_stock SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                            ->execute([':qty' => $countedQty, ':id' => $stockRow['id']]);
                    } else {
                        $this->db->prepare("
                            INSERT INTO inventory_stock (product_id, variant_id, location_id, quantity)
                            VALUES (:pid, :vid, :lid, :qty)
                        ")->execute([':pid' => $pid, ':vid' => $vid, ':lid' => $count['location_id'], ':qty' => $countedQty]);
                    }

                    $stmtMovement->execute([
                        ':pid'    => $pid,
                        ':vid'    => $vid,
                        ':lid'    => $count['location_id'],
                        ':qty'    => abs($countedQty - $sysQty),
                        ':before' => $sysQty,
                        ':after'  => $countedQty,
                        ':ref_id' => $countId,
                        ':notes'  => "Stock count {$count['reference_number']} correction: {$sysQty} -> {$countedQty}",
                        ':by'     => $reconciledBy,
                    ]);
                }
            }

            $this->db->prepare("UPDATE stock_counts SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $countId]);

            $this->db->commit();
            return $this->getStockCount($countId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
