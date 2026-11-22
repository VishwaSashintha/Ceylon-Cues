<?php

declare(strict_types=1);

namespace CeylonCues\Services\Orders;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

final class ReturnService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function createReturn(int $orderId, array $items, string $reason, ?int $userId = null): array
    {
        $stmtOrder = $this->db->prepare("SELECT * FROM orders WHERE id = :id");
        $stmtOrder->execute([':id' => $orderId]);
        $order = $stmtOrder->fetch();
        if (!$order) {
            throw new RuntimeException("Order with ID {$orderId} not found");
        }

        if (empty($items)) {
            throw new RuntimeException("At least one return item must be provided");
        }

        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM return_requests WHERE reference_number LIKE 'CC-RET-{$year}-%'")->fetchColumn() + 1;
        $refNumber = sprintf('CC-RET-%s-%04d', $year, $count);
        $uuid = Uuid::v4();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO return_requests (
                    uuid, reference_number, order_id, user_id, reason, status
                ) VALUES (
                    :uuid, :ref, :order_id, :user_id, :reason, 'requested'
                )
            ");
            $stmt->execute([
                ':uuid'     => $uuid,
                ':ref'      => $refNumber,
                ':order_id' => $orderId,
                ':user_id'  => $userId,
                ':reason'   => $reason,
            ]);
            $returnId = (int)$this->db->lastInsertId();

            $stmtItem = $this->db->prepare("
                INSERT INTO return_items (return_id, order_item_id, quantity, condition_notes, restock)
                VALUES (:ret_id, :oi_id, :qty, :notes, 0)
            ");

            foreach ($items as $item) {
                $oiId = (int)$item['order_item_id'];
                $qty = (int)$item['quantity'];

                $stmtCheck = $this->db->prepare("SELECT quantity FROM order_items WHERE id = :id AND order_id = :oid");
                $stmtCheck->execute([':id' => $oiId, ':oid' => $orderId]);
                $oiQty = $stmtCheck->fetchColumn();
                if ($oiQty === false || $qty > (int)$oiQty || $qty <= 0) {
                    throw new RuntimeException("Invalid return item quantity for item {$oiId}");
                }

                $stmtItem->execute([
                    ':ret_id' => $returnId,
                    ':oi_id'  => $oiId,
                    ':qty'    => $qty,
                    ':notes'  => $item['condition_notes'] ?? null,
                ]);
            }

            $this->db->commit();
            return $this->getReturn($returnId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function listReturns(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'rr.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(rr.reference_number LIKE :search OR o.reference_number LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM return_requests rr
            JOIN orders o ON rr.order_id = o.id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT rr.*, o.reference_number as order_reference, o.customer_name, o.customer_email
            FROM return_requests rr
            JOIN orders o ON rr.order_id = o.id
            WHERE {$whereClause}
            ORDER BY rr.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $returns = $stmt->fetchAll();

        return [
            'data'         => $returns,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
            'last_page'    => (int)ceil($total / $perPage),
        ];
    }

    public function getReturn(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'rr.id' : 'rr.reference_number';
        $stmt = $this->db->prepare("
            SELECT rr.*, o.reference_number as order_reference, o.customer_name, o.customer_phone, o.customer_email,
                   u.first_name as user_name, pb.first_name as processed_by_name
            FROM return_requests rr
            JOIN orders o ON rr.order_id = o.id
            LEFT JOIN users u ON rr.user_id = u.id
            LEFT JOIN users pb ON rr.processed_by = pb.id
            WHERE {$column} = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $return = $stmt->fetch();
        if (!$return) {
            return null;
        }

        $stmtItems = $this->db->prepare("
            SELECT ri.*, oi.product_id, oi.unit_price, p.name as product_name, p.sku as product_sku
            FROM return_items ri
            JOIN order_items oi ON ri.order_item_id = oi.id
            JOIN products p ON oi.product_id = p.id
            WHERE ri.return_id = :rid
        ");
        $stmtItems->execute([':rid' => $return['id']]);
        $return['items'] = $stmtItems->fetchAll();

        return $return;
    }

    public function approveReturn(int $returnId, int $processedBy): array
    {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new RuntimeException("Return request with ID {$returnId} not found");
        }

        if ($return['status'] !== 'requested') {
            throw new RuntimeException("Only requested returns can be approved");
        }

        $stmt = $this->db->prepare("
            UPDATE return_requests 
            SET status = 'approved', processed_by = :pby, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':pby' => $processedBy, ':id' => $returnId]);

        return $this->getReturn($returnId);
    }

    public function rejectReturn(int $returnId, string $rejectionReason, int $processedBy): array
    {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new RuntimeException("Return request with ID {$returnId} not found");
        }

        if ($return['status'] !== 'requested') {
            throw new RuntimeException("Only requested returns can be rejected");
        }

        $stmt = $this->db->prepare("
            UPDATE return_requests 
            SET status = 'rejected', rejection_reason = :reason, processed_by = :pby, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':reason' => $rejectionReason,
            ':pby'    => $processedBy,
            ':id'     => $returnId,
        ]);

        return $this->getReturn($returnId);
    }

    public function receiveReturnedGoods(int $returnId, int $locationId, int $receivedBy, bool $restoreStock = true, ?string $notes = null): array
    {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new RuntimeException("Return request with ID {$returnId} not found");
        }

        if ($return['status'] !== 'approved') {
            throw new RuntimeException("Cannot restore or receive stock for an unapproved return request");
        }

        $this->db->beginTransaction();
        try {
            $stmtUpdateReturn = $this->db->prepare("
                UPDATE return_requests 
                SET status = 'received', processed_by = :pby, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpdateReturn->execute([':pby' => $receivedBy, ':id' => $returnId]);

            if ($restoreStock) {
                $stmtStockCheck = $this->db->prepare("
                    SELECT id, quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND variant_id IS NULL
                    FOR UPDATE
                ");

                $stmtMovement = $this->db->prepare("
                    INSERT INTO stock_movements (
                        product_id, variant_id, location_id,
                        movement_type, quantity, reference_id, reference_type, notes, created_by
                    ) VALUES (
                        :pid, NULL, :loc_id,
                        'return', :qty, :ref_id, 'return_request', :notes, :created_by
                    )
                ");

                $stmtUpdateItem = $this->db->prepare("UPDATE return_items SET restock = 1 WHERE id = :id");

                foreach ($return['items'] as $item) {
                    $pid = (int)$item['product_id'];
                    $qty = (int)$item['quantity'];

                    $stmtStockCheck->execute([':pid' => $pid, ':lid' => $locationId]);
                    $stockRow = $stmtStockCheck->fetch();

                    if ($stockRow) {
                        $this->db->prepare("UPDATE inventory_stock SET quantity = quantity + :qty, updated_at = NOW() WHERE id = :id")
                            ->execute([':qty' => $qty, ':id' => $stockRow['id']]);
                    } else {
                        $this->db->prepare("
                            INSERT INTO inventory_stock (product_id, variant_id, location_id, quantity, reserved_quantity, reorder_level)
                            VALUES (:pid, NULL, :lid, :qty, 0, 5)
                        ")->execute([':pid' => $pid, ':lid' => $locationId, ':qty' => $qty]);
                    }

                    $stmtMovement->execute([
                        ':pid'        => $pid,
                        ':loc_id'     => $locationId,
                        ':qty'        => $qty,
                        ':ref_id'     => $returnId,
                        ':notes'      => $notes ?: "Restocked from approved return {$return['reference_number']}",
                        ':created_by' => $receivedBy,
                    ]);
                    $stmtUpdateItem->execute([':id' => $item['id']]);
                }
            }

            $this->db->commit();
            return $this->getReturn($returnId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
