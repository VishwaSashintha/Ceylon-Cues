<?php

declare(strict_types=1);

namespace CeylonCues\Services\Pos;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use CeylonCues\Services\Orders\OrderService;
use PDO;
use RuntimeException;

final class PosService
{
    private PDO $db;
    private OrderService $orderService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->orderService = new OrderService();
    }

    public function openSession(int $cashierId, int $locationId, float $openingBalance = 0.00, ?string $notes = null): array
    {
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

    public function closeSession(int $sessionId, float $closingBalance, ?string $notes = null): array
    {
        $session = $this->getSession($sessionId);
        if (!$session || $session['closed_at'] !== null) {
            throw new RuntimeException('Active session not found or already closed');
        }

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

    public function processSale(
        int $sessionId,
        int $cashierId,
        array $items,
        string $paymentMethod = 'cash',
        ?float $cashReceived = null,
        ?string $cardReference = null,
        ?string $customerName = 'Walk-in Customer',
        ?string $customerPhone = '0000000000',
        float $discountAmount = 0.00,
        array $data = []
    ): array {
        $session = $this->getSession($sessionId);
        if (!$session || $session['closed_at'] !== null) {
            throw new RuntimeException('Active POS session required');
        }

        $locationId = (int)$session['location_id'];
        $splitPayments = $data['split_payments'] ?? [];

        $this->db->beginTransaction();
        try {
            $order = $this->orderService->createOrder([
                'channel'                => 'pos',
                'customer_name'          => $customerName ?: 'Walk-in Customer',
                'customer_phone'         => $customerPhone ?: '0000000000',
                'items'                  => $items,
                'discount_amount'        => $discountAmount,
                'fulfilment_location_id' => $locationId,
                'delivery_method'        => 'shop_collection',
            ], $cashierId);

            $orderId    = (int)$order['id'];
            $totalAmount = (float)$order['total_amount'];

            $changeGiven = 0.00;
            if (empty($splitPayments)) {
                if ($paymentMethod === 'cash') {
                    $cashReceived = $cashReceived ?? $totalAmount;
                    if ($cashReceived < $totalAmount) {
                        throw new RuntimeException("Cash received (LKR {$cashReceived}) is less than total amount (LKR {$totalAmount})");
                    }
                    $changeGiven = $cashReceived - $totalAmount;
                }
            }

            $year    = date('Y');
            $txCount = (int)$this->db->query("SELECT COUNT(*) FROM pos_transactions WHERE reference_number LIKE 'CC-POS-{$year}-%'")->fetchColumn() + 1;
            $posRef  = sprintf('CC-POS-%s-%06d', $year, $txCount);
            $uuid    = Uuid::v4();

            $offlineRef = $data['offline_ref'] ?? null;

            $stmtTx = $this->db->prepare("
                INSERT INTO pos_transactions (
                    uuid, reference_number, session_id, order_id, cashier_id, location_id,
                    payment_method, cash_received, change_given, card_reference, offline_ref
                ) VALUES (
                    :uuid, :ref, :sid, :oid, :cid, :lid,
                    :pmethod, :crecv, :cchange, :card_ref, :offline_ref
                )
            ");
            $stmtTx->execute([
                ':uuid'        => $uuid,
                ':ref'         => $posRef,
                ':sid'         => $sessionId,
                ':oid'         => $orderId,
                ':cid'         => $cashierId,
                ':lid'         => $locationId,
                ':pmethod'     => $paymentMethod,
                ':crecv'       => $cashReceived,
                ':cchange'     => $changeGiven,
                ':card_ref'    => $cardReference,
                ':offline_ref' => $offlineRef,
            ]);

            $posTransactionId = (int)$this->db->lastInsertId();

            if (!empty($splitPayments)) {
                $stmtSplit = $this->db->prepare("
                    INSERT INTO pos_transactions_payments (pos_transaction_id, payment_method, amount)
                    VALUES (:txid, :method, :amount)
                ");
                foreach ($splitPayments as $sp) {
                    $stmtSplit->execute([
                        ':txid'   => $posTransactionId,
                        ':method' => $sp['method'],
                        ':amount' => (float)$sp['amount'],
                    ]);
                }
                $effectiveMethod = 'split';
            } else {
                $effectiveMethod = $paymentMethod;
            }

            $payCount = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE reference_number LIKE 'CC-PAY-{$year}-%'")->fetchColumn() + 1;
            $payRef   = sprintf('CC-PAY-%s-%06d', $year, $payCount);

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
                ':pmethod' => $effectiveMethod,
                ':amount'  => $totalAmount,
            ]);

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'transaction_reference' => $posRef,
                'order_reference'       => $order['reference_number'],
                'subtotal'              => (float)$order['subtotal'],
                'discount'              => $discountAmount,
                'total_amount'          => $totalAmount,
                'payment_method'        => $effectiveMethod,
                'cash_received'         => $cashReceived,
                'change_given'          => $changeGiven,
                'split_payments'        => $splitPayments,
                'items'                 => $order['items'],
                'cashier_id'            => $cashierId,
                'timestamp'             => date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function syncOfflineSales(array $sales, int $cashierId): array
    {
        $processed = 0;
        $skipped   = 0;
        $errors    = [];

        foreach ($sales as $index => $sale) {
            $offlineRef = $sale['offline_ref'] ?? null;

            if ($offlineRef) {
                $check = $this->db->prepare("SELECT id FROM pos_transactions WHERE offline_ref = :ref LIMIT 1");
                $check->execute([':ref' => $offlineRef]);
                if ($check->fetch()) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $this->processSale(
                    (int)($sale['pos_session_id']),
                    $cashierId,
                    $sale['items'] ?? [],
                    $sale['payment_method'] ?? 'cash',
                    isset($sale['cash_received']) ? (float)$sale['cash_received'] : null,
                    $sale['card_reference'] ?? null,
                    $sale['customer_name'] ?? 'Walk-in Customer',
                    $sale['customer_phone'] ?? '0000000000',
                    (float)($sale['discount_amount'] ?? 0.00),
                    [
                        'offline_ref'    => $offlineRef,
                        'split_payments' => $sale['split_payments'] ?? [],
                    ]
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = ['index' => $index, 'offline_ref' => $offlineRef, 'error' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function verifyManagerPin(string $pin, int $sessionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM users
            WHERE role IN ('owner', 'shop_manager')
              AND manager_pin = SHA2(:pin, 256)
            LIMIT 1
        ");
        $stmt->execute([':pin' => $pin]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        $this->recordPosOverride($sessionId, 'pin_verify', (int)$user['id'], null, []);

        return true;
    }

    public function recordPosOverride(int $sessionId, string $actionType, int $authorizedByUserId, ?int $transactionId, array $details): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO pos_overrides (session_id, action_type, authorized_by_user_id, transaction_id, details, created_at)
            VALUES (:sid, :action, :uid, :txid, :details, NOW())
        ");
        $stmt->execute([
            ':sid'     => $sessionId,
            ':action'  => $actionType,
            ':uid'     => $authorizedByUserId,
            ':txid'    => $transactionId,
            ':details' => json_encode($details),
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateTerminalInfo(int $sessionId, string $terminalId): void
    {
        $this->db->prepare("UPDATE pos_sessions SET terminal_id = :tid WHERE id = :id")
            ->execute([':tid' => $terminalId, ':id' => $sessionId]);
    }

    public function getCashFloat(int $sessionId): array
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            throw new RuntimeException('POS session not found');
        }

        $openingFloat = (float)($session['opening_float'] ?? $session['opening_balance'] ?? 0.00);
        $cashIn       = (float)($session['total_cash_in'] ?? 0.00);
        $cashOut      = (float)($session['total_cash_out'] ?? 0.00);

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(o.total_amount), 0)
            FROM pos_transactions pt
            JOIN orders o ON pt.order_id = o.id
            WHERE pt.session_id = :sid AND pt.payment_method = 'cash' AND pt.is_return = 0
        ");
        $stmt->execute([':sid' => $sessionId]);
        $cashSales = (float)$stmt->fetchColumn();

        $expectedBalance = $openingFloat + $cashIn + $cashSales - $cashOut;
        $closingBalance  = isset($session['closing_balance']) ? (float)$session['closing_balance'] : null;
        $variance        = $closingBalance !== null ? $closingBalance - $expectedBalance : null;

        return [
            'session_id'       => $sessionId,
            'opening_float'    => $openingFloat,
            'total_cash_in'    => $cashIn,
            'total_cash_out'   => $cashOut,
            'cash_sales'       => $cashSales,
            'expected_balance' => $expectedBalance,
            'closing_balance'  => $closingBalance,
            'variance'         => $variance,
        ];
    }

    public function recordCashMovement(int $sessionId, string $type, float $amount, string $reason): void
    {
        if ($type === 'in') {
            $this->db->prepare("UPDATE pos_sessions SET total_cash_in = COALESCE(total_cash_in, 0) + :amt WHERE id = :id")
                ->execute([':amt' => $amount, ':id' => $sessionId]);
        } elseif ($type === 'out') {
            $this->db->prepare("UPDATE pos_sessions SET total_cash_out = COALESCE(total_cash_out, 0) + :amt WHERE id = :id")
                ->execute([':amt' => $amount, ':id' => $sessionId]);
        } else {
            throw new RuntimeException("Cash movement type must be 'in' or 'out'");
        }

        $this->db->prepare("
            INSERT INTO pos_cash_movements (session_id, type, amount, reason, created_at)
            VALUES (:sid, :type, :amt, :reason, NOW())
        ")->execute([
            ':sid'    => $sessionId,
            ':type'   => $type,
            ':amt'    => $amount,
            ':reason' => $reason,
        ]);
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
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['cashier_id'])) {
            $where[]          = 's.cashier_id = :cid';
            $params[':cid']   = (int)$filters['cashier_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[]          = 's.location_id = :lid';
            $params[':lid']   = (int)$filters['location_id'];
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
        $stmt   = $this->db->prepare("
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
        $offset  = max(0, ($page - 1) * $perPage);
        $where   = ['1=1'];
        $params  = [];

        if (!empty($filters['session_id'])) {
            $where[]          = 'pt.session_id = :sid';
            $params[':sid']   = (int)$filters['session_id'];
        }
        if (!empty($filters['payment_method'])) {
            $where[]              = 'pt.payment_method = :pmethod';
            $params[':pmethod']   = $filters['payment_method'];
        }
        if (!empty($filters['search'])) {
            $where[]              = '(pt.reference_number LIKE :search OR o.reference_number LIKE :search)';
            $params[':search']    = '%' . $filters['search'] . '%';
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
        $where      = ["DATE(pt.created_at) = :date"];
        $params     = [':date' => $targetDate];

        if ($locationId) {
            $where[]        = 'pt.location_id = :lid';
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
