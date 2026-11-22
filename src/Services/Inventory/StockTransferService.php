<?php

declare(strict_types=1);

namespace CeylonCues\Services\Inventory;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Stock Transfer Engine
 *
 * Manages inventory movements between locations (e.g. Warehouse -> Retail Shop).
 * Guarantees zero stock loss, ledger audit trails, and strict transaction integrity.
 */
final class StockTransferService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a stock transfer request.
     *
     * @param int   $fromLocationId Origin location (e.g. Main Warehouse)
     * @param int   $toLocationId   Destination location (e.g. Physical Shop)
     * @param array $items          Array of ['product_id' => ..., 'quantity' => ..., 'variant_id' => ...]
     * @param int   $requestedBy    User requesting the transfer
     * @param string|null $notes    Optional notes
     */
    public function createTransfer(
        int $fromLocationId,
        int $toLocationId,
        array $items,
        int $requestedBy,
        ?string $notes = null
    ): array {
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException('Source and destination locations cannot be the same');
        }

        if (empty($items)) {
            throw new RuntimeException('Transfer must contain at least one item');
        }

        $this->db->beginTransaction();
        try {
            $year = date('Y');
            $count = (int)$this->db->query("SELECT COUNT(*) FROM stock_transfers WHERE reference_number LIKE 'CC-TRF-{$year}-%'")->fetchColumn() + 1;
            $refNumber = sprintf('CC-TRF-%s-%06d', $year, $count);
            $uuid = Uuid::v4();

            $stmt = $this->db->prepare("
                INSERT INTO stock_transfers (
                    uuid, reference_number, from_location_id, to_location_id, status, notes, requested_by
                ) VALUES (
                    :uuid, :ref, :from_loc, :to_loc, 'pending', :notes, :by
                )
            ");
            $stmt->execute([
                ':uuid'     => $uuid,
                ':ref'      => $refNumber,
                ':from_loc' => $fromLocationId,
                ':to_loc'   => $toLocationId,
                ':notes'    => $notes,
                ':by'       => $requestedBy,
            ]);
            $transferId = (int)$this->db->lastInsertId();

            $stmtItem = $this->db->prepare("
                INSERT INTO stock_transfer_items (transfer_id, product_id, variant_id, quantity_requested)
                VALUES (:tid, :pid, :vid, :qty)
            ");

            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $qty = (int)$item['quantity'];

                if ($qty <= 0) {
                    throw new RuntimeException('Transfer quantity must be greater than zero');
                }

                // Verify source stock has enough units
                $stockCheck = $this->db->prepare("
                    SELECT quantity, reserved_quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                ");
                $stockCheck->execute([':pid' => $pid, ':lid' => $fromLocationId, ':vid' => $vid, ':vid2' => $vid]);
                $stock = $stockCheck->fetch();

                $available = $stock ? ((int)$stock['quantity'] - (int)$stock['reserved_quantity']) : 0;
                if ($available < $qty) {
                    $prodName = $this->db->query("SELECT name FROM products WHERE id = {$pid}")->fetchColumn() ?: "Product #{$pid}";
                    throw new RuntimeException("Insufficient available stock at origin for '{$prodName}'. Available: {$available}, Requested: {$qty}");
                }

                $stmtItem->execute([':tid' => $transferId, ':pid' => $pid, ':vid' => $vid, ':qty' => $qty]);
            }

            $this->db->commit();
            return $this->getTransfer($transferId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Complete a stock transfer (transfers quantities between locations & updates ledger).
     *
     * Example: Warehouse 50, Shop 10 -> Transfer 5 -> Warehouse 45, Shop 15. Total 60.
     */
    public function completeTransfer(int $transferId, int $completedBy): array
    {
        $this->db->beginTransaction();
        try {
            $transfer = $this->getTransfer($transferId);
            if (!$transfer) {
                throw new RuntimeException('Stock transfer not found');
            }

            if ($transfer['status'] === 'completed') {
                throw new RuntimeException('Transfer has already been completed');
            }
            if ($transfer['status'] === 'cancelled') {
                throw new RuntimeException('Cannot complete a cancelled transfer');
            }

            $fromLoc = (int)$transfer['from_location_id'];
            $toLoc = (int)$transfer['to_location_id'];

            foreach ($transfer['items'] as $item) {
                $pid = (int)$item['product_id'];
                $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;
                $qty = (int)$item['quantity_requested'];

                // 1. Lock and deduct from source location
                $stmtFrom = $this->db->prepare("
                    SELECT id, quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                    FOR UPDATE
                ");
                $stmtFrom->execute([':pid' => $pid, ':lid' => $fromLoc, ':vid' => $vid, ':vid2' => $vid]);
                $sourceStock = $stmtFrom->fetch();

                if (!$sourceStock || (int)$sourceStock['quantity'] < $qty) {
                    throw new RuntimeException("Cannot complete transfer: Origin location does not have {$qty} units available");
                }

                $sourceBefore = (int)$sourceStock['quantity'];
                $sourceAfter = $sourceBefore - $qty;

                $this->db->prepare("UPDATE inventory_stock SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                    ->execute([':qty' => $sourceAfter, ':id' => $sourceStock['id']]);

                // Record transfer_out ledger
                $cost = (float)$this->db->query("SELECT cost_price FROM products WHERE id = {$pid}")->fetchColumn();
                $this->db->prepare("
                    INSERT INTO stock_movements (
                        product_id, variant_id, location_id, movement_type,
                        quantity, quantity_before, quantity_after, reference_type, reference_id, unit_cost, notes, created_by
                    ) VALUES (
                        :pid, :vid, :lid, 'transfer_out',
                        :qty, :before, :after, 'stock_transfer', :ref_id, :cost, :notes, :by
                    )
                ")->execute([
                    ':pid'     => $pid,
                    ':vid'     => $vid,
                    ':lid'     => $fromLoc,
                    ':qty'     => $qty,
                    ':before'  => $sourceBefore,
                    ':after'   => $sourceAfter,
                    ':ref_id'  => $transferId,
                    ':cost'    => $cost,
                    ':notes'   => "Transfer to location #{$toLoc} ({$transfer['reference_number']})",
                    ':by'      => $completedBy,
                ]);

                // 2. Lock and add to destination location
                $stmtTo = $this->db->prepare("
                    SELECT id, quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                    FOR UPDATE
                ");
                $stmtTo->execute([':pid' => $pid, ':lid' => $toLoc, ':vid' => $vid, ':vid2' => $vid]);
                $destStock = $stmtTo->fetch();

                $destBefore = $destStock ? (int)$destStock['quantity'] : 0;
                $destAfter = $destBefore + $qty;

                if ($destStock) {
                    $this->db->prepare("UPDATE inventory_stock SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                        ->execute([':qty' => $destAfter, ':id' => $destStock['id']]);
                } else {
                    $this->db->prepare("
                        INSERT INTO inventory_stock (product_id, variant_id, location_id, quantity)
                        VALUES (:pid, :vid, :lid, :qty)
                    ")->execute([':pid' => $pid, ':vid' => $vid, ':lid' => $toLoc, ':qty' => $destAfter]);
                }

                // Record transfer_in ledger
                $this->db->prepare("
                    INSERT INTO stock_movements (
                        product_id, variant_id, location_id, movement_type,
                        quantity, quantity_before, quantity_after, reference_type, reference_id, unit_cost, notes, created_by
                    ) VALUES (
                        :pid, :vid, :lid, 'transfer_in',
                        :qty, :before, :after, 'stock_transfer', :ref_id, :cost, :notes, :by
                    )
                ")->execute([
                    ':pid'     => $pid,
                    ':vid'     => $vid,
                    ':lid'     => $toLoc,
                    ':qty'     => $qty,
                    ':before'  => $destBefore,
                    ':after'   => $destAfter,
                    ':ref_id'  => $transferId,
                    ':cost'    => $cost,
                    ':notes'   => "Transfer from location #{$fromLoc} ({$transfer['reference_number']})",
                    ':by'      => $completedBy,
                ]);

                // Update transfer item sent/received
                $this->db->prepare("
                    UPDATE stock_transfer_items
                    SET quantity_sent = :sent, quantity_received = :recv
                    WHERE id = :id
                ")->execute([':sent' => $qty, ':recv' => $qty, ':id' => $item['id']]);
            }

            // Mark transfer completed
            $this->db->prepare("
                UPDATE stock_transfers
                SET status = 'completed', approved_by = :approved_by, completed_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ")->execute([':approved_by' => $completedBy, ':id' => $transferId]);

            $this->db->commit();
            return $this->getTransfer($transferId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getTransfer(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, fl.name as from_location_name, tl.name as to_location_name,
                   ru.first_name as requested_by_name, au.first_name as approved_by_name
            FROM stock_transfers t
            JOIN inventory_locations fl ON t.from_location_id = fl.id
            JOIN inventory_locations tl ON t.to_location_id = tl.id
            LEFT JOIN users ru ON t.requested_by = ru.id
            LEFT JOIN users au ON t.approved_by = au.id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $transfer = $stmt->fetch();

        if (!$transfer) {
            return null;
        }

        $itemsStmt = $this->db->prepare("
            SELECT ti.*, p.name as product_name, p.sku
            FROM stock_transfer_items ti
            JOIN products p ON ti.product_id = p.id
            WHERE ti.transfer_id = :tid
        ");
        $itemsStmt->execute([':tid' => $id]);
        $transfer['items'] = $itemsStmt->fetchAll();

        return $transfer;
    }

    public function listTransfers(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = :status';
            $params[':status'] = $filters['status'];
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT t.*, fl.name as from_location_name, tl.name as to_location_name,
                   ru.first_name as requested_by_first, ru.last_name as requested_by_last
            FROM stock_transfers t
            JOIN inventory_locations fl ON t.from_location_id = fl.id
            JOIN inventory_locations tl ON t.to_location_id = tl.id
            LEFT JOIN users ru ON t.requested_by = ru.id
            WHERE {$whereSql}
            ORDER BY t.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
