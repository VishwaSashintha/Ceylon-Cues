<?php

declare(strict_types=1);

namespace CeylonCues\Services\Pos;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use CeylonCues\Services\Orders\OrderService;
use PDO;
use RuntimeException;

/**
 * Shop POS Transaction Engine
 *
 * Drives physical retail checkout, session balances, barcode scanning,
 * and immediate shop inventory deductions.
 */
final class PosService
{
    private PDO $db;
    private OrderService $orderService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->orderService = new OrderService();
    }

    /**
     * Open a cashier register session.
     */
    public function openSession(int $cashierId, int $locationId, float $openingBalance = 0.00, ?string $notes = null): array
    {
        // Check if cashier already has an active open session
        $stmt = $this->db->prepare("SELECT id FROM pos_sessions WHERE cashier_id = :cid AND closed_at IS NULL LIMIT 1");
        $stmt->execute([':cid' => $cashierId]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Cashier already has an open POS session. Please close it first.');
        }

        $uuid = Uuid::v4();
        $stmt = $this->db->prepare("
            INSERT INTO pos_sessions (uuid, cashier_id, location_id, opening_balance, notes)
            VALUES (:uuid, :cid, :lid, :balance, :notes)
        ");
        $stmt->execute([
            ':uuid'    => $uuid,
            ':cid'     => $cashierId,
            ':lid'     => $locationId,
            ':balance' => $openingBalance,
            ':notes'   => $notes,
        ]);

        return $this->getSession((int)$this->db->lastInsertId());
    }

    /**
     * Close a cashier register session.
     */
    public function closeSession(int $sessionId, float $closingBalance, ?string $notes = null): array
    {
        $session = $this->getSession($sessionId);
        if (!$session || $session['closed_at'] !== null) {
            throw new RuntimeException('Active session not found or already closed');
        }

        // Calculate expected balance: opening balance + total cash transactions
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(o.total_amount), 0)
            FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            WHERE pt.session_id = :sid AND pt.payment_method = 'cash' AND pt.is_return = 0
        ");
        $stmt->execute([':sid' => $sessionId]);
        $cashSales = (float)$stmt->fetchColumn();

        $expected = (float)$session['opening_balance'] + $cashSales;

        $this->db->prepare("
            UPDATE pos_sessions
            SET closed_at = NOW(), closing_balance = :closing, expected_balance = :expected, notes = :notes
            WHERE id = :id
        ")->execute([
            ':closing'  => $closingBalance,
            ':expected' => $expected,
            ':notes'    => $notes,
            ':id'       => $sessionId,
        ]);

        return $this->getSession($sessionId);
    }

    /**
     * Process a retail sale at the POS register.
     */
    public function processSale(
        int $sessionId,
        int $cashierId,
        array $items,
        string $paymentMethod = 'cash',
        ?float $cashReceived = null,
        ?string $cardReference = null,
        ?string $customerName = 'Walk-in Customer',
        ?string $customerPhone = '0000000000',
        float $discountAmount = 0.00
    ): array {
        $session = $this->getSession($sessionId);
        if (!$session || $session['closed_at'] !== null) {
            throw new RuntimeException('Active POS session required');
        }

        $locationId = (int)$session['location_id'];

        $this->db->beginTransaction();
        try {
            // 1. Create order through Central Order Engine
            $order = $this->orderService->createOrder([
                'channel'                => 'pos',
                'customer_name'          => $customerName ?: 'Walk-in Customer',
                'customer_phone'         => $customerPhone ?: '0000000000',
                'items'                  => $items,
                'discount_amount'        => $discountAmount,
                'fulfilment_location_id' => $locationId,
                'delivery_method'        => 'shop_collection',
            ], $cashierId);

            $orderId = (int)$order['id'];
            $totalAmount = (float)$order['total_amount'];

            $changeGiven = 0.00;
            if ($paymentMethod === 'cash') {
                $cashReceived = $cashReceived ?? $totalAmount;
                if ($cashReceived < $totalAmount) {
                    throw new RuntimeException("Cash received (LKR {$cashReceived}) is less than total amount (LKR {$totalAmount})");
                }
                $changeGiven = $cashReceived - $totalAmount;
            }

            // 2. Generate POS Transaction Reference
            $year = date('Y');
            $txCount = (int)$this->db->query("SELECT COUNT(*) FROM pos_transactions WHERE reference_number LIKE 'CC-POS-{$year}-%'")->fetchColumn() + 1;
            $posRef = sprintf('CC-POS-%s-%06d', $year, $txCount);
            $uuid = Uuid::v4();

            $stmtTx = $this->db->prepare("
                INSERT INTO pos_transactions (
                    uuid, reference_number, session_id, order_id, cashier_id, location_id,
                    payment_method, cash_received, change_given, card_reference
                ) VALUES (
                    :uuid, :ref, :sid, :oid, :cid, :lid,
                    :pmethod, :crecv, :cchange, :card_ref
                )
            ");
            $stmtTx->execute([
                ':uuid'     => $uuid,
                ':ref'      => $posRef,
                ':sid'      => $sessionId,
                ':oid'      => $orderId,
                ':cid'      => $cashierId,
                ':lid'      => $locationId,
                ':pmethod'  => $paymentMethod,
                ':crecv'    => $cashReceived,
                ':cchange'  => $changeGiven,
                ':card_ref' => $cardReference,
            ]);

            // 3. Record Payment
            $payCount = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE reference_number LIKE 'CC-PAY-{$year}-%'")->fetchColumn() + 1;
            $payRef = sprintf('CC-PAY-%s-%06d', $year, $payCount);

            $this->db->prepare("
                INSERT INTO payments (
                    uuid, reference_number, order_id, payment_method, amount, currency, status, paid_at
                ) VALUES (
                    :uuid, :ref, :oid, :pmethod, :amount, 'LKR', 'completed', NOW()
                )
            ")->execute([
                ':uuid'    => Uuid::v4(),
                ':ref'     => $payRef,
                ':oid'     => $orderId,
                ':pmethod' => $paymentMethod === 'card' ? 'card' : 'cash',
                ':amount'  => $totalAmount,
            ]);

            $this->db->commit();

            return [
                'transaction_reference' => $posRef,
                'order_reference'       => $order['reference_number'],
                'subtotal'              => (float)$order['subtotal'],
                'discount'              => $discountAmount,
                'total_amount'          => $totalAmount,
                'payment_method'        => $paymentMethod,
                'cash_received'         => $cashReceived,
                'change_given'          => $changeGiven,
                'items'                 => $order['items'],
                'cashier_id'            => $cashierId,
                'timestamp'             => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getSession(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, u.first_name as cashier_name, l.name as location_name
            FROM pos_sessions s
            JOIN users u ON s.cashier_id = u.id
            JOIN inventory_locations l ON s.location_id = l.id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $session = $stmt->fetch();
        return $session ?: null;
    }

    public function listSessions(array $filters = [], int $limit = 50): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['cashier_id'])) {
            $where[] = 's.cashier_id = :cid';
            $params[':cid'] = (int)$filters['cashier_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = 's.location_id = :lid';
            $params[':lid'] = (int)$filters['location_id'];
        }
        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT s.*, u.first_name as cashier_name, l.name as location_name
            FROM pos_sessions s
            JOIN users u ON s.cashier_id = u.id
            JOIN inventory_locations l ON s.location_id = l.id
            WHERE {$whereClause}
            ORDER BY s.created_at DESC
            LIMIT :limit
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getTransaction(int|string $identifier): ?array
    {
        $column = is_numeric($identifier) ? 'pt.id' : 'pt.reference_number';
        $stmt = $this->db->prepare("
            SELECT pt.*, o.reference_number as order_reference, o.total_amount, o.subtotal, o.discount_amount,
                   u.first_name as cashier_name, l.name as location_name
            FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            JOIN users u ON pt.cashier_id = u.id
            JOIN inventory_locations l ON pt.location_id = l.id
            WHERE {$column} = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $tx = $stmt->fetch();
        if (!$tx) {
            return null;
        }

        $stmtItems = $this->db->prepare("
            SELECT oi.*, p.name as product_name, p.sku
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = :oid
        ");
        $stmtItems->execute([':oid' => $tx['order_id']]);
        $tx['items'] = $stmtItems->fetchAll();

        return $tx;
    }

    public function listTransactions(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['session_id'])) {
            $where[] = 'pt.session_id = :sid';
            $params[':sid'] = (int)$filters['session_id'];
        }
        if (!empty($filters['payment_method'])) {
            $where[] = 'pt.payment_method = :pmethod';
            $params[':pmethod'] = $filters['payment_method'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(pt.reference_number LIKE :search OR o.reference_number LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT pt.*, o.reference_number as order_reference, o.total_amount,
                   u.first_name as cashier_name, l.name as location_name
            FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            JOIN users u ON pt.cashier_id = u.id
            JOIN inventory_locations l ON pt.location_id = l.id
            WHERE {$whereClause}
            ORDER BY pt.created_at DESC
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

    public function getDailySummary(?string $date = null, ?int $locationId = null): array
    {
        $targetDate = $date ?: date('Y-m-d');
        $where = ["DATE(pt.created_at) = :date"];
        $params = [':date' => $targetDate];

        if ($locationId) {
            $where[] = 'pt.location_id = :lid';
            $params[':lid'] = $locationId;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as transaction_count,
                COALESCE(SUM(o.total_amount), 0) as total_sales,
                COALESCE(SUM(CASE WHEN pt.payment_method = 'cash' THEN o.total_amount ELSE 0 END), 0) as cash_sales,
                COALESCE(SUM(CASE WHEN pt.payment_method = 'card' THEN o.total_amount ELSE 0 END), 0) as card_sales,
                COALESCE(SUM(CASE WHEN pt.payment_method = 'bank_transfer' THEN o.total_amount ELSE 0 END), 0) as transfer_sales,
                COALESCE(SUM(o.discount_amount), 0) as total_discounts
            FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            WHERE {$whereClause}
        ");
        $stmt->execute($params);
        $summary = $stmt->fetch();

        return [
            'date'              => $targetDate,
            'location_id'       => $locationId,
            'transaction_count' => (int)$summary['transaction_count'],
            'total_sales'       => (float)$summary['total_sales'],
            'cash_sales'        => (float)$summary['cash_sales'],
            'card_sales'        => (float)$summary['card_sales'],
            'transfer_sales'    => (float)$summary['transfer_sales'],
            'total_discounts'   => (float)$summary['total_discounts'],
            'currency'          => 'LKR',
        ];
    }
}
