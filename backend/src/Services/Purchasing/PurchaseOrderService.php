<?php

declare(strict_types=1);

namespace CeylonCues\Services\Purchasing;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

final class PurchaseOrderService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function createPurchaseOrder(array $data, int $userId): array
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('Purchase order must contain at least one item');
        }

        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM purchase_orders WHERE reference_number LIKE 'CC-PO-{$year}-%'")->fetchColumn() + 1;
        $refNumber = sprintf('CC-PO-%s-%04d', $year, $count);
        $uuid = Uuid::v4();

        $subtotal = 0.00;
        foreach ($data['items'] as $item) {
            $qty = (int)$item['quantity'];
            $cost = (float)$item['unit_cost'];
            $subtotal += ($qty * $cost);
        }

        $taxAmount = (float)($data['tax_amount'] ?? 0.00);
        $shippingCost = (float)($data['shipping_cost'] ?? 0.00);
        $totalAmount = $subtotal + $taxAmount + $shippingCost;

        $currencyCode = strtoupper(trim($data['currency_code'] ?? 'LKR'));
        $exchangeRate = (float)($data['exchange_rate'] ?? 1.0000);
        $dutyPercent  = (float)($data['duty_percent'] ?? 0.00);
        $freightCost  = (float)($data['freight_cost'] ?? 0.00);

        // Calculate landed cost in LKR
        $subtotalLkr   = $subtotal * $exchangeRate;
        $dutyAmountLkr = $subtotalLkr * ($dutyPercent / 100.0);
        $landedCostLkr = round($subtotalLkr + $dutyAmountLkr + $freightCost + ($shippingCost * $exchangeRate), 2);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO purchase_orders (
                    uuid, reference_number, supplier_id, currency_code, exchange_rate, duty_percent, freight_cost, landed_cost_lkr,
                    status, expected_date, notes, subtotal, tax_amount, shipping_cost, total_amount, created_by
                ) VALUES (
                    :uuid, :ref, :supplier_id, :curr, :rate, :duty, :freight, :landed,
                    'draft', :expected_date, :notes, :subtotal, :tax_amount, :shipping_cost, :total_amount, :created_by
                )
            ");
            $stmt->execute([
                ':uuid'          => $uuid,
                ':ref'           => $refNumber,
                ':supplier_id'   => (int)$data['supplier_id'],
                ':curr'          => $currencyCode,
                ':rate'          => $exchangeRate,
                ':duty'          => $dutyPercent,
                ':freight'       => $freightCost,
                ':landed'        => $landedCostLkr,
                ':expected_date' => $data['expected_date'] ?? null,
                ':notes'         => $data['notes'] ?? null,
                ':subtotal'      => $subtotal,
                ':tax_amount'    => $taxAmount,
                ':shipping_cost' => $shippingCost,
                ':total_amount'  => $totalAmount,
                ':created_by'    => $userId,
            ]);

            $poId = (int)$this->db->lastInsertId();

            $stmtItem = $this->db->prepare("
                INSERT INTO purchase_order_items (
                    purchase_order_id, product_id, variant_id, quantity_ordered, quantity_received, unit_cost, total_cost, notes
                ) VALUES (
                    :po_id, :pid, :vid, :qty, 0, :unit_cost, :total_cost, :notes
                )
            ");

            foreach ($data['items'] as $item) {
                $qty = (int)$item['quantity'];
                $cost = (float)$item['unit_cost'];
                $itemTotal = $qty * $cost;

                $stmtItem->execute([
                    ':po_id'      => $poId,
                    ':pid'        => (int)$item['product_id'],
                    ':vid'        => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                    ':qty'        => $qty,
                    ':unit_cost'  => $cost,
                    ':total_cost' => $itemTotal,
                    ':notes'      => $item['notes'] ?? null,
                ]);
            }

            $this->db->commit();
            return $this->getPurchaseOrder($poId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function listPurchaseOrders(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'po.supplier_id = :supplier_id';
            $params[':supplier_id'] = (int)$filters['supplier_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'po.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(po.reference_number LIKE :search OR s.company_name LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM purchase_orders po 
            JOIN suppliers s ON po.supplier_id = s.id 
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT po.*, s.company_name as supplier_name, u.first_name as created_by_name
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE {$whereClause}
            ORDER BY po.created_at DESC
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

    public function getPurchaseOrder(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'po.id' : 'po.reference_number';
        $stmt = $this->db->prepare("
            SELECT po.*, s.company_name as supplier_name, s.email as supplier_email, s.phone as supplier_phone,
                   u.first_name as created_by_name, ap.first_name as approved_by_name
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            LEFT JOIN users ap ON po.approved_by = ap.id
            WHERE {$column} = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $po = $stmt->fetch();
        if (!$po) {
            return null;
        }

        $stmtItems = $this->db->prepare("
            SELECT poi.*, p.name as product_name, p.sku as product_sku
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = :poid
        ");
        $stmtItems->execute([':poid' => $po['id']]);
        $po['items'] = $stmtItems->fetchAll();

        $stmtReceipts = $this->db->prepare("
            SELECT gr.*, l.name as location_name, u.first_name as received_by_name
            FROM goods_receipts gr
            JOIN inventory_locations l ON gr.location_id = l.id
            JOIN users u ON gr.received_by = u.id
            WHERE gr.purchase_order_id = :poid
            ORDER BY gr.created_at DESC
        ");
        $stmtReceipts->execute([':poid' => $po['id']]);
        $po['receipts'] = $stmtReceipts->fetchAll();

        return $po;
    }

    public function updatePurchaseOrderStatus(int $id, string $status, ?string $notes = null, ?int $userId = null): array
    {
        $allowed = ['draft', 'submitted', 'confirmed', 'partial_received', 'received', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException("Invalid purchase order status: {$status}");
        }

        $po = $this->getPurchaseOrder($id);
        if (!$po) {
            throw new RuntimeException("Purchase order with ID {$id} not found");
        }

        $params = [':status' => $status, ':id' => $id];
        $extraSql = '';
        if ($status === 'confirmed' && $userId !== null) {
            $extraSql = ', approved_by = :approved_by';
            $params[':approved_by'] = $userId;
        }

        $stmt = $this->db->prepare("UPDATE purchase_orders SET status = :status {$extraSql}, updated_at = NOW() WHERE id = :id");
        $stmt->execute($params);

        return $this->getPurchaseOrder($id);
    }

    public function receiveGoods(int $poId, int $locationId, array $items, int $receivedBy, ?string $notes = null): array
    {
        $po = $this->getPurchaseOrder($poId);
        if (!$po) {
            throw new RuntimeException("Purchase order with ID {$poId} not found");
        }

        if (in_array($po['status'], ['draft', 'cancelled', 'received'], true)) {
            throw new RuntimeException("Cannot receive goods for purchase order with status '{$po['status']}'");
        }

        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM goods_receipts WHERE reference_number LIKE 'CC-GRN-{$year}-%'")->fetchColumn() + 1;
        $grnRef = sprintf('CC-GRN-%s-%04d', $year, $count);
        $uuid = Uuid::v4();

        $this->db->beginTransaction();
        try {
            $stmtGrn = $this->db->prepare("
                INSERT INTO goods_receipts (uuid, reference_number, purchase_order_id, location_id, received_by, notes)
                VALUES (:uuid, :ref, :po_id, :loc_id, :recv_by, :notes)
            ");
            $stmtGrn->execute([
                ':uuid'    => $uuid,
                ':ref'     => $grnRef,
                ':po_id'   => $poId,
                ':loc_id'  => $locationId,
                ':recv_by' => $receivedBy,
                ':notes'   => $notes,
            ]);
            $grnId = (int)$this->db->lastInsertId();

            $stmtGrnItem = $this->db->prepare("
                INSERT INTO goods_receipt_items (
                    receipt_id, purchase_order_item_id, product_id, variant_id, quantity_received, unit_cost, condition_notes
                ) VALUES (
                    :grn_id, :poi_id, :pid, :vid, :qty, :cost, :notes
                )
            ");

            $stmtUpdatePoi = $this->db->prepare("
                UPDATE purchase_order_items 
                SET quantity_received = quantity_received + :qty 
                WHERE id = :id
            ");

            $stmtMovement = $this->db->prepare("
                INSERT INTO stock_movements (
                    product_id, variant_id, location_id,
                    movement_type, quantity, reference_id, reference_type, notes, created_by
                ) VALUES (
                    :pid, :vid, :loc_id,
                    'receive', :qty, :ref_id, 'goods_receipt', :notes, :created_by
                )
            ");

            foreach ($items as $item) {
                $poiId = (int)$item['purchase_order_item_id'];
                $qty = (int)$item['quantity_received'];
                if ($qty <= 0) {
                    continue;
                }

                $poi = null;
                foreach ($po['items'] as $pi) {
                    if ((int)$pi['id'] === $poiId) {
                        $poi = $pi;
                        break;
                    }
                }
                if (!$poi) {
                    throw new RuntimeException("PO item {$poiId} not found in this order");
                }

                $productId = (int)$poi['product_id'];
                $variantId = !empty($poi['variant_id']) ? (int)$poi['variant_id'] : null;

                $stmtGrnItem->execute([
                    ':grn_id' => $grnId,
                    ':poi_id' => $poiId,
                    ':pid'    => $productId,
                    ':vid'    => $variantId,
                    ':qty'    => $qty,
                    ':cost'   => (float)$poi['unit_cost'],
                    ':notes'  => $item['notes'] ?? null,
                ]);

                $stmtUpdatePoi->execute([':qty' => $qty, ':id' => $poiId]);

                $stmtStockCheck = $this->db->prepare("
                    SELECT id, quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                    FOR UPDATE
                ");
                $stmtStockCheck->execute([':pid' => $productId, ':lid' => $locationId, ':vid' => $variantId, ':vid2' => $variantId]);
                $stockRow = $stmtStockCheck->fetch();

                if ($stockRow) {
                    $this->db->prepare("UPDATE inventory_stock SET quantity = quantity + :qty, updated_at = NOW() WHERE id = :id")
                        ->execute([':qty' => $qty, ':id' => $stockRow['id']]);
                } else {
                    $this->db->prepare("
                        INSERT INTO inventory_stock (product_id, variant_id, location_id, quantity, reserved_quantity, reorder_level)
                        VALUES (:pid, :vid, :lid, :qty, 0, 5)
                    ")->execute([':pid' => $productId, ':vid' => $variantId, ':lid' => $locationId, ':qty' => $qty]);
                }

                $stmtMovement->execute([
                    ':pid'        => $productId,
                    ':vid'        => $variantId,
                    ':loc_id'     => $locationId,
                    ':qty'        => $qty,
                    ':ref_id'     => $grnId,
                    ':notes'      => "Received via {$grnRef} against PO {$po['reference_number']}",
                    ':created_by' => $receivedBy,
                ]);
            }

            $stmtCheck = $this->db->prepare("SELECT quantity_ordered, quantity_received FROM purchase_order_items WHERE purchase_order_id = :poid");
            $stmtCheck->execute([':poid' => $poId]);
            $allRows = $stmtCheck->fetchAll();

            $allFullyReceived = true;
            foreach ($allRows as $r) {
                if ((int)$r['quantity_received'] < (int)$r['quantity_ordered']) {
                    $allFullyReceived = false;
                    break;
                }
            }

            $newStatus = $allFullyReceived ? 'received' : 'partial_received';
            $this->db->prepare("UPDATE purchase_orders SET status = :status, updated_at = NOW() WHERE id = :id")
                ->execute([':status' => $newStatus, ':id' => $poId]);

            $this->db->commit();
            return [
                'receipt_reference' => $grnRef,
                'goods_receipt_id'  => $grnId,
                'status'            => $newStatus,
                'po'                => $this->getPurchaseOrder($poId),
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
