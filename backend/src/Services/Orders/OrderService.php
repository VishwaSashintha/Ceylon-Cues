<?php

declare(strict_types=1);

namespace CeylonCues\Services\Orders;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use CeylonCues\Services\Inventory\InventoryService;
use CeylonCues\Services\Inventory\StockAllocationService;
use CeylonCues\Services\Settings\SettingService;
use PDO;
use RuntimeException;

/**
 * Central Order & Transaction Engine
 *
 * Powers Online Orders, POS Transactions, Wholesale Orders, and Manual Orders.
 * Permanently stores price/SKU/cost historical snapshots and guarantees inventory safety.
 */
final class OrderService
{
    private PDO $db;
    private InventoryService $inventory;
    private StockAllocationService $allocation;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->inventory = new InventoryService();
        $this->allocation = new StockAllocationService();
    }

    /**
     * Create an order across any channel.
     *
     * @param array $orderData {
     *   channel: 'online'|'pos'|'wholesale'|'manual',
     *   customer_name: string,
     *   customer_phone: string,
     *   customer_email: ?string,
     *   user_id: ?int,
     *   items: array of ['product_id', 'quantity', 'variant_id'?, 'custom_price'?],
     *   delivery_method: ?string,
     *   delivery_zone_id: ?int,
     *   delivery_charge: ?float,
     *   discount_code: ?string,
     *   discount_amount: ?float,
     *   fulfilment_location_id: ?int,
     *   shipping_address: ?array,
     *   notes: ?string
     * }
     */
    public function createOrder(array $orderData, ?int $createdBy = null): array
    {
        $channel = $orderData['channel'] ?? 'online';
        $items = $orderData['items'] ?? [];

        if (empty($items)) {
            throw new RuntimeException('Order must contain at least one item');
        }

        $isOwnTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $isOwnTx = true;
        }
        try {
            $year = date('Y');
            $refPrefix = match ($channel) {
                'pos'       => "CC-POS-{$year}",
                'wholesale' => "CC-WHO-{$year}",
                default     => "CC-ORD-{$year}",
            };

            // Collision-resistant reference number generation
            $count = (int)$this->db->query("SELECT COUNT(*) FROM orders WHERE reference_number LIKE '{$refPrefix}-%'")->fetchColumn() + 1;
            $refNumber = sprintf('%s-%06d', $refPrefix, $count);
            $uuid = Uuid::v4();

            // Default fulfilment location
            $fulfilmentLocId = $orderData['fulfilment_location_id'] ?? null;
            if (!$fulfilmentLocId) {
                if ($channel === 'pos') {
                    $fulfilmentLocId = (int)$this->db->query("SELECT id FROM inventory_locations WHERE type = 'shop' LIMIT 1")->fetchColumn();
                } else {
                    $fulfilmentLocId = (int)$this->db->query("SELECT id FROM inventory_locations WHERE is_default = 1 LIMIT 1")->fetchColumn();
                }
            }

            $subtotal = 0.00;
            $orderItems = [];

            // Validate inventory and prepare historical snapshots
            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $vid = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;

                if ($qty <= 0) {
                    throw new RuntimeException('Item quantity must be greater than zero');
                }

                // Lock product for consistent pricing & SKU snapshot
                $stmtProd = $this->db->prepare("
                    SELECT id, name, sku, retail_price, wholesale_price, cost_price, is_active
                    FROM products WHERE id = :id LOCK IN SHARE MODE
                ");
                $stmtProd->execute([':id' => $pid]);
                $product = $stmtProd->fetch();

                if (!$product || !$product['is_active']) {
                    throw new RuntimeException("Product #{$pid} is inactive or not found");
                }

                // Check stock availability at fulfilment location
                $stmtStock = $this->db->prepare("
                    SELECT quantity, reserved_quantity FROM inventory_stock
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                    FOR UPDATE
                ");
                $stmtStock->execute([':pid' => $pid, ':lid' => $fulfilmentLocId, ':vid' => $vid, ':vid2' => $vid]);
                $stock = $stmtStock->fetch();

                $available = $stock ? ((int)$stock['quantity'] - (int)$stock['reserved_quantity']) : 0;

                // For online channel, also check online allocation
                if ($channel === 'online') {
                    $alloc = $this->allocation->getAllocation($pid, $vid);
                    $onlineAvail = (int)($alloc['allocated_quantity'] ?? 0);
                    if ($onlineAvail < $qty && empty($alloc['allow_backorder'])) {
                        throw new RuntimeException("Online stock allocation exceeded for '{$product['name']}'. Available: {$onlineAvail}");
                    }
                }

                if ($channel === 'pos' && $available < $qty) {
                    if (empty($orderData['allow_offline_oversell'])) {
                        throw new RuntimeException("Insufficient shop inventory for '{$product['name']}'. Available in shop: {$available}, Requested: {$qty}");
                    }
                    // Oversell permitted by offline policy — log audit note
                    $this->db->prepare("
                        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address, user_agent)
                        VALUES (:uid, 'offline_oversell_variance', 'inventory_stock', :pid, :vals, '127.0.0.1', 'pos_offline_sync')
                    ")->execute([
                        ':uid'  => $createdBy ?: 1,
                        ':pid'  => $pid,
                        ':vals' => json_encode(['requested' => $qty, 'available_was' => $available, 'product' => $product['name']]),
                    ]);
                }

                // Determine unit price
                $unitPrice = match ($channel) {
                    'wholesale' => (float)($item['custom_price'] ?? $product['wholesale_price'] ?? $product['retail_price']),
                    default     => (float)($item['custom_price'] ?? $product['retail_price']),
                };

                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;

                $orderItems[] = [
                    'product_id'            => $pid,
                    'variant_id'            => $vid,
                    'sku_snapshot'          => $product['sku'],
                    'product_name_snapshot' => $product['name'],
                    'variant_name_snapshot' => null,
                    'quantity'              => $qty,
                    'unit_price'            => $unitPrice,
                    'total_price'           => $lineTotal,
                    'cost_price_snapshot'   => (float)$product['cost_price'],
                    'location_id'           => $fulfilmentLocId,
                ];
            }

            $discountAmount = min($subtotal, (float)($orderData['discount_amount'] ?? 0.00));
            $deliveryCharge = (float)($orderData['delivery_charge'] ?? 0.00);
            $totalAmount = max(0.00, ($subtotal - $discountAmount) + $deliveryCharge);

            $initialStatus = $channel === 'pos' ? 'delivered' : 'pending';
            $paymentStatus = $channel === 'pos' ? 'paid' : 'unpaid';

            // Insert Order
            $stmtOrder = $this->db->prepare("
                INSERT INTO orders (
                    uuid, reference_number, user_id, channel, status, payment_status,
                    customer_name, customer_email, customer_phone,
                    shipping_address_snapshot, subtotal, discount_amount, discount_code,
                    delivery_charge, total_amount, currency, delivery_method,
                    delivery_zone_id, fulfilment_location_id, notes, created_by
                ) VALUES (
                    :uuid, :ref, :user_id, :channel, :status, :payment_status,
                    :name, :email, :phone,
                    :addr, :subtotal, :disc, :disc_code,
                    :del_charge, :total, 'LKR', :del_method,
                    :zone_id, :fulfilment_loc, :notes, :created_by
                )
            ");

            $stmtOrder->execute([
                ':uuid'           => $uuid,
                ':ref'            => $refNumber,
                ':user_id'        => $orderData['user_id'] ?? null,
                ':channel'        => $channel,
                ':status'         => $initialStatus,
                ':payment_status' => $paymentStatus,
                ':name'           => trim($orderData['customer_name']),
                ':email'          => !empty($orderData['customer_email']) ? trim($orderData['customer_email']) : null,
                ':phone'          => trim($orderData['customer_phone']),
                ':addr'           => isset($orderData['shipping_address']) ? json_encode($orderData['shipping_address'], JSON_UNESCAPED_UNICODE) : null,
                ':subtotal'       => $subtotal,
                ':disc'           => $discountAmount,
                ':disc_code'      => $orderData['discount_code'] ?? null,
                ':del_charge'     => $deliveryCharge,
                ':total'          => $totalAmount,
                ':del_method'     => $orderData['delivery_method'] ?? ($channel === 'pos' ? 'shop_collection' : 'home_delivery'),
                ':zone_id'        => $orderData['delivery_zone_id'] ?? null,
                ':fulfilment_loc' => $fulfilmentLocId,
                ':notes'          => $orderData['notes'] ?? null,
                ':created_by'     => $createdBy,
            ]);

            $orderId = (int)$this->db->lastInsertId();

            // Insert Items and adjust/reserve stock
            $stmtInsertItem = $this->db->prepare("
                INSERT INTO order_items (
                    order_id, product_id, variant_id, sku_snapshot, product_name_snapshot,
                    variant_name_snapshot, quantity, unit_price, total_price, cost_price_snapshot, location_id
                ) VALUES (
                    :oid, :pid, :vid, :sku, :pname, :vname, :qty, :uprice, :tprice, :cost, :lid
                )
            ");

            foreach ($orderItems as $item) {
                $stmtInsertItem->execute([
                    ':oid'    => $orderId,
                    ':pid'    => $item['product_id'],
                    ':vid'    => $item['variant_id'],
                    ':sku'    => $item['sku_snapshot'],
                    ':pname'  => $item['product_name_snapshot'],
                    ':vname'  => $item['variant_name_snapshot'],
                    ':qty'    => $item['quantity'],
                    ':uprice' => $item['unit_price'],
                    ':tprice' => $item['total_price'],
                    ':cost'   => $item['cost_price_snapshot'],
                    ':lid'    => $item['location_id'],
                ]);

                if ($channel === 'pos') {
                    // For POS sales: immediately deduct stock and write movement ledger
                    $this->deductStockForSale($item['product_id'], $fulfilmentLocId, $item['quantity'], $orderId, $refNumber, 'pos_sale', $createdBy, $item['variant_id']);
                } else {
                    // For online/wholesale orders: reserve physical stock and deduct from online allocation
                    $this->inventory->reserveStock($item['product_id'], $fulfilmentLocId, $item['quantity'], $item['variant_id']);
                    if ($channel === 'online') {
                        $this->allocation->deductOnlineStock($item['product_id'], $item['quantity'], $item['variant_id']);
                    }
                }
            }

            // Record status history
            $this->recordStatusHistory($orderId, null, $initialStatus, "Order created via {$channel} channel", $createdBy);

            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            $savedOrder = $this->getOrder($orderId);
            if (!empty($savedOrder['customer_email'])) {
                try {
                    $mailer = new \CeylonCues\Services\Mail\MailService();
                    $mailer->sendOrderConfirmation(
                        $savedOrder['customer_email'],
                        $savedOrder['customer_name'] ?? 'Valued Customer',
                        $savedOrder['reference_number'] ?? (string)$orderId,
                        (float)$savedOrder['total_amount'],
                        $savedOrder['items'] ?? []
                    );
                } catch (\Throwable) {
                    // Logged inside MailService
                }
            }

            return $savedOrder;
        } catch (\Throwable $e) {
            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Deduct stock immediately and record ledger movement.
     */
    public function deductStockForSale(
        int $productId,
        int $locationId,
        int $quantity,
        int $orderId,
        string $referenceNumber,
        string $movementType = 'sale',
        ?int $userId = null,
        ?int $variantId = null
    ): void {
        $stmt = $this->db->prepare("
            SELECT id, quantity FROM inventory_stock
            WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
            FOR UPDATE
        ");
        $stmt->execute([':pid' => $productId, ':lid' => $locationId, ':vid' => $variantId, ':vid2' => $variantId]);
        $stock = $stmt->fetch();

        $before = $stock ? (int)$stock['quantity'] : 0;
        $after = max(0, $before - $quantity);

        if ($stock) {
            $this->db->prepare("UPDATE inventory_stock SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                ->execute([':qty' => $after, ':id' => $stock['id']]);
        }

        $cost = (float)$this->db->query("SELECT cost_price FROM products WHERE id = {$productId}")->fetchColumn();

        $this->db->prepare("
            INSERT INTO stock_movements (
                product_id, variant_id, location_id, movement_type,
                quantity, quantity_before, quantity_after, reference_type, reference_id, unit_cost, notes, created_by
            ) VALUES (
                :pid, :vid, :lid, :type,
                :qty, :before, :after, 'order', :ref_id, :cost, :notes, :by
            )
        ")->execute([
            ':pid'     => $productId,
            ':vid'     => $variantId,
            ':lid'     => $locationId,
            ':type'     => $movementType,
            ':qty'     => $quantity,
            ':before'  => $before,
            ':after'   => $after,
            ':ref_id'  => $orderId,
            ':cost'    => $cost,
            ':notes'   => "Sale order {$referenceNumber}",
            ':by'      => $userId,
        ]);
    }

    /**
     * Transition order status (with automatic stock fulfilment or cancellation restock).
     */
    public function updateOrderStatus(int $orderId, string $newStatus, ?string $notes = null, ?int $changedBy = null): array
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        $oldStatus = $order['status'];
        if ($oldStatus === $newStatus) {
            return $order;
        }

        $isOwnTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $isOwnTx = true;
        }
        try {
            // When order is shipped or completed, release reservation and officially deduct physical stock
            if (in_array($newStatus, ['shipped', 'delivered', 'collected'], true) && !in_array($oldStatus, ['shipped', 'delivered', 'collected'], true)) {
                $fulfilmentLoc = (int)($order['fulfilment_location_id'] ?? 1);
                foreach ($order['items'] as $item) {
                    // Release reservation
                    $this->inventory->releaseReservedStock((int)$item['product_id'], $fulfilmentLoc, (int)$item['quantity'], $item['variant_id'] ? (int)$item['variant_id'] : null);
                    // Deduct stock permanently
                    $this->deductStockForSale(
                        (int)$item['product_id'],
                        $fulfilmentLoc,
                        (int)$item['quantity'],
                        $orderId,
                        $order['reference_number'],
                        'sale',
                        $changedBy,
                        $item['variant_id'] ? (int)$item['variant_id'] : null
                    );
                }
            }

            // When order is cancelled: release reservation and restore online allocation
            if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                $fulfilmentLoc = (int)($order['fulfilment_location_id'] ?? 1);
                foreach ($order['items'] as $item) {
                    $this->inventory->releaseReservedStock((int)$item['product_id'], $fulfilmentLoc, (int)$item['quantity'], $item['variant_id'] ? (int)$item['variant_id'] : null);
                    if ($order['channel'] === 'online') {
                        // Restore online allocation
                        $this->db->prepare("
                            UPDATE online_stock_allocations SET allocated_quantity = allocated_quantity + :qty WHERE product_id = :pid
                        ")->execute([':qty' => $item['quantity'], ':pid' => $item['product_id']]);
                    }
                }
            }

            $this->db->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id")
                ->execute([':status' => $newStatus, ':id' => $orderId]);

            $this->recordStatusHistory($orderId, $oldStatus, $newStatus, $notes, $changedBy);

            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            $savedOrder = $this->getOrder($orderId);
            if (!empty($savedOrder['customer_email'])) {
                try {
                    $mailer = new \CeylonCues\Services\Mail\MailService();
                    $mailer->sendOrderConfirmation(
                        $savedOrder['customer_email'],
                        $savedOrder['customer_name'] ?? 'Valued Customer',
                        $savedOrder['reference_number'] ?? (string)$orderId,
                        (float)$savedOrder['total_amount'],
                        $savedOrder['items'] ?? []
                    );
                } catch (\Throwable) {
                    // Logged inside MailService
                }
            }

            return $savedOrder;
        } catch (\Throwable $e) {
            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getOrder(int|string $identifier): ?array
    {
        $col = is_numeric($identifier) ? 'o.id' : 'o.reference_number';
        $stmt = $this->db->prepare("
            SELECT o.*, fl.name as fulfilment_location_name, dz.name as delivery_zone_name
            FROM orders o
            LEFT JOIN inventory_locations fl ON o.fulfilment_location_id = fl.id
            LEFT JOIN delivery_zones dz ON o.delivery_zone_id = dz.id
            WHERE {$col} = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $itemsStmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id = :oid");
        $itemsStmt->execute([':oid' => $order['id']]);
        $order['items'] = $itemsStmt->fetchAll();

        $historyStmt = $this->db->prepare("SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY created_at ASC");
        $historyStmt->execute([':oid' => $order['id']]);
        $order['status_history'] = $historyStmt->fetchAll();

        return $order;
    }

    public function listOrders(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[] = 'o.channel = :channel';
            $params[':channel'] = $filters['channel'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'o.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'o.user_id = :uid';
            $params[':uid'] = $filters['user_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(o.reference_number LIKE :search OR o.customer_name LIKE :search OR o.customer_phone LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $count = (int)$this->db->query("SELECT COUNT(*) FROM orders o WHERE {$whereSql}")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            WHERE {$whereSql}
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        return [
            'items' => $items,
            'total' => $count,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function generateTaxInvoiceNumber(int $orderId): string
    {
        $this->db->beginTransaction();
        try {
            $year = date('Y');
            $prefix = "CC-TAX-INV-{$year}-";

            $stmt = $this->db->prepare("
                SELECT MAX(CAST(SUBSTRING_INDEX(tax_invoice_number, '-', -1) AS UNSIGNED))
                FROM orders
                WHERE tax_invoice_number LIKE :prefix
                FOR UPDATE
            ");
            $stmt->execute([':prefix' => $prefix . '%']);
            $maxSeq = (int)$stmt->fetchColumn();
            $seq = $maxSeq + 1;

            $invoiceNumber = $prefix . sprintf('%05d', $seq);

            $this->db->prepare("UPDATE orders SET tax_invoice_number = :inv, updated_at = NOW() WHERE id = :id")
                     ->execute([':inv' => $invoiceNumber, ':id' => $orderId]);

            $this->db->commit();
            return $invoiceNumber;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getTaxInvoiceData(int $orderId): array
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new RuntimeException("Order #{$orderId} not found.");
        }

        if (empty($order['tax_invoice_number'])) {
            $order['tax_invoice_number'] = $this->generateTaxInvoiceNumber($orderId);
            $order = $this->getOrder($orderId);
        }

        $customer = null;
        if (!empty($order['user_id'])) {
            $stmt = $this->db->prepare("
                SELECT id, uuid, email, phone, first_name, last_name FROM users WHERE id = :uid LIMIT 1
            ");
            $stmt->execute([':uid' => (int)$order['user_id']]);
            $customer = $stmt->fetch() ?: null;
        }

        $settings = new SettingService();
        $businessName = $settings->get('business_name', 'Ceylon Cues');
        $businessAddress = $settings->get('business_address', '');
        $businessPhone = $settings->get('business_phone', '');
        $vatNumber = $settings->get('vat_number', '');

        return [
            'order'            => $order,
            'tax_invoice_number' => $order['tax_invoice_number'],
            'vat_percentage'   => (float)($order['vat_percentage'] ?? 0),
            'vat_amount'       => (float)($order['vat_amount'] ?? 0),
            'items'            => $order['items'],
            'customer'         => $customer,
            'business'         => [
                'name'        => $businessName,
                'address'     => $businessAddress,
                'phone'       => $businessPhone,
                'vat_number'  => $vatNumber,
            ],
        ];
    }

    private function recordStatusHistory(int $orderId, ?string $old, string $new, ?string $notes = null, ?int $by = null): void
    {
        $this->db->prepare("
            INSERT INTO order_status_history (order_id, old_status, new_status, notes, changed_by)
            VALUES (:oid, :old, :new, :notes, :by)
        ")->execute([':oid' => $orderId, ':old' => $old, ':new' => $new, ':notes' => $notes, ':by' => $by]);
    }
}
