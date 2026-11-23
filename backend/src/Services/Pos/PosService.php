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
                'allow_offline_oversell' => !empty($data['allow_offline_oversell']),
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

            $policy = $sale['oversell_policy'] ?? 'allow_and_audit';
            $allowOversell = ($policy === 'allow_and_audit');

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
                        'offline_ref'            => $offlineRef,
                        'split_payments'         => $sale['split_payments'] ?? [],
                        'allow_offline_oversell' => $allowOversell,
                    ]
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = ['index' => $index, 'offline_ref' => $offlineRef, 'policy' => $policy, 'error' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function setManagerPin(int $userId, string $pin): void
    {
        if (strlen($pin) < 4 || strlen($pin) > 12) {
            throw new InvalidArgumentException('Manager PIN must be between 4 and 12 characters');
        }

        $hash = password_hash($pin, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        $this->db->prepare("UPDATE users SET manager_pin = :hash, updated_at = NOW() WHERE id = :id")
            ->execute([':hash' => $hash, ':id' => $userId]);
    }

    public function verifyManagerPin(string $pin, int $sessionId): bool
    {
        // Check lockout status
        $lockStmt = $this->db->prepare("
            SELECT failed_attempts, locked_until
            FROM pos_pin_attempts
            WHERE session_id = :sid
            LIMIT 1
        ");
        $lockStmt->execute([':sid' => $sessionId]);
        $lock = $lockStmt->fetch();

        if ($lock && !empty($lock['locked_until'])) {
            if (strtotime($lock['locked_until']) > time()) {
                $remainingSec = strtotime($lock['locked_until']) - time();
                $remainingMin = max(1, (int)ceil($remainingSec / 60));
                throw new RuntimeException("Manager PIN is locked due to repeated failed attempts. Please retry in {$remainingMin} minute(s).");
            }
        }

        // Fetch managers with active PINs
        $stmt = $this->db->prepare("
            SELECT id, manager_pin
            FROM users
            WHERE role IN ('owner', 'shop_manager')
              AND is_active = 1
              AND manager_pin IS NOT NULL
        ");
        $stmt->execute();
        $managers = $stmt->fetchAll();

        $verifiedUserId = null;
        foreach ($managers as $mgr) {
            if (password_verify($pin, $mgr['manager_pin'])) {
                $verifiedUserId = (int)$mgr['id'];
                // Check if rehash needed
                if (password_needs_rehash($mgr['manager_pin'], PASSWORD_ARGON2ID)) {
                    $this->setManagerPin($verifiedUserId, $pin);
                }
                break;
            }
        }

        if ($verifiedUserId !== null) {
            // Reset lockout attempts on success
            $this->db->prepare("DELETE FROM pos_pin_attempts WHERE session_id = :sid")
                ->execute([':sid' => $sessionId]);

            $this->recordPosOverride($sessionId, 'pin_verify', $verifiedUserId, null, []);
            return true;
        }

        // Record failed attempt
        $failedAttempts = ($lock['failed_attempts'] ?? 0) + 1;
        $lockedUntil = null;
        if ($failedAttempts >= 5) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (15 * 60));
        }

        $this->db->prepare("
            INSERT INTO pos_pin_attempts (session_id, failed_attempts, locked_until, updated_at)
            VALUES (:sid, :fails, :lock, NOW())
            ON DUPLICATE KEY UPDATE
                failed_attempts = :fails,
                locked_until    = :lock,
                updated_at      = NOW()
        ")->execute([
            ':sid'   => $sessionId,
            ':fails' => $failedAttempts,
            ':lock'  => $lockedUntil,
        ]);

        return false;
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

    // ── Hold & Resume ──────────────────────────────────────────────────────

    public function holdSale(int $sessionId, mixed $cart, ?string $label): array
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `pos_held_sales` (
                `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `session_id` BIGINT UNSIGNED NOT NULL,
                `label`      VARCHAR(100) DEFAULT NULL,
                `cart_data`  JSON NOT NULL,
                `held_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_held_session` (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $this->db->prepare("
            INSERT INTO pos_held_sales (session_id, label, cart_data)
            VALUES (:sid, :label, :cart)
        ");
        $stmt->execute([
            ':sid'   => $sessionId,
            ':label' => $label,
            ':cart'  => json_encode($cart, JSON_UNESCAPED_UNICODE),
        ]);

        return ['held_id' => (int)$this->db->lastInsertId(), 'session_id' => $sessionId, 'label' => $label];
    }

    public function listHeldSales(int $sessionId): array
    {
        $stmt = $this->db->prepare("SELECT id, session_id, label, cart_data, held_at FROM pos_held_sales WHERE session_id = :sid ORDER BY held_at DESC");
        $stmt->execute([':sid' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['cart_data'] = json_decode($row['cart_data'], true);
        }
        return $rows;
    }

    public function resumeHeldSale(int $sessionId, int $heldId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pos_held_sales WHERE id = :id AND session_id = :sid");
        $stmt->execute([':id' => $heldId, ':sid' => $sessionId]);
        $held = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$held) {
            throw new RuntimeException('Held sale not found');
        }
        // Remove from held sales when resumed
        $this->db->prepare("DELETE FROM pos_held_sales WHERE id = :id")->execute([':id' => $heldId]);
        return ['cart' => json_decode($held['cart_data'], true), 'label' => $held['label']];
    }

    // ── Void Transaction ───────────────────────────────────────────────────

    public function voidTransaction(int $txId, string $reason, int $managerSessionId, int $cashierId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM pos_transactions WHERE id = :id");
        $stmt->execute([':id' => $txId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            throw new RuntimeException("POS transaction #{$txId} not found");
        }
        if ($tx['voided_at'] ?? null) {
            throw new RuntimeException("Transaction #{$txId} is already voided");
        }

        $this->db->beginTransaction();
        try {
            // Mark transaction voided
            $this->db->prepare("UPDATE pos_transactions SET voided_at = NOW(), void_reason = :reason WHERE id = :id")
                ->execute([':reason' => $reason, ':id' => $txId]);

            // Restore stock
            if (!empty($tx['order_id'])) {
                $items = $this->db->prepare("SELECT product_id, variant_id, quantity, location_id FROM order_items WHERE order_id = :oid");
                $items->execute([':oid' => $tx['order_id']]);
                foreach ($items->fetchAll() as $item) {
                    $lid = (int)($item['location_id'] ?? 1);
                    $this->db->prepare("
                        UPDATE inventory_stock
                        SET quantity = quantity + :qty, reserved_quantity = GREATEST(0, reserved_quantity - :qty), updated_at = NOW()
                        WHERE product_id = :pid AND location_id = :lid
                    ")->execute([':qty' => (int)$item['quantity'], ':pid' => (int)$item['product_id'], ':lid' => $lid]);
                }
            }

            // Record override
            $this->db->prepare("
                INSERT INTO pos_overrides (session_id, cashier_id, manager_id, action, amount, reason)
                VALUES (:sid, :cid, :mid, 'void_sale', :amt, :reason)
            ")->execute([
                ':sid'    => (int)$tx['session_id'],
                ':cid'    => $cashierId,
                ':mid'    => $managerSessionId,
                ':amt'    => (float)($tx['total_amount'] ?? 0),
                ':reason' => $reason,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return ['transaction_id' => $txId, 'voided' => true];
    }

    // ── Receipt (80mm Thermal Format) ─────────────────────────────────────

    public function getReceiptData(int $txId): array
    {
        $stmt = $this->db->prepare("SELECT pt.*, o.reference_number, o.total_amount, o.tax_amount, o.discount_amount, o.tax_invoice_number FROM pos_transactions pt LEFT JOIN orders o ON pt.order_id = o.id WHERE pt.id = :id");
        $stmt->execute([':id' => $txId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) throw new RuntimeException("Transaction #{$txId} not found");

        $items = [];
        if (!empty($tx['order_id'])) {
            $iStmt = $this->db->prepare("SELECT oi.quantity, oi.unit_price, oi.total_price, p.name, p.barcode FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = :oid");
            $iStmt->execute([':oid' => $tx['order_id']]);
            $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $bizStmt = $this->db->query("SELECT setting_key, setting_value FROM business_settings WHERE setting_key IN ('business_name','shop_address','support_phone','tax_rate_percent')");
        $biz = [];
        foreach ($bizStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $biz[$r['setting_key']] = $r['setting_value'];
        }

        return [
            'business'         => $biz,
            'transaction_id'   => $txId,
            'reference'        => $tx['reference_number'],
            'tax_invoice'      => $tx['tax_invoice_number'],
            'terminal_id'      => $tx['terminal_id'] ?? null,
            'served_by'        => $tx['served_by'] ?? null,
            'items'            => $items,
            'subtotal'         => (float)$tx['total_amount'],
            'discount'         => (float)($tx['discount_amount'] ?? 0),
            'tax'              => (float)($tx['tax_amount'] ?? 0),
            'total'            => (float)$tx['total_amount'],
            'payment_method'   => $tx['payment_method'] ?? null,
            'cash_tendered'    => isset($tx['cash_tendered']) ? (float)$tx['cash_tendered'] : null,
            'change_given'     => isset($tx['change_given']) ? (float)$tx['change_given'] : null,
            'created_at'       => $tx['created_at'],
            'paper_width_mm'   => 80,
        ];
    }

    // ── Terminal Management ───────────────────────────────────────────────

    public function registerTerminal(string $terminalId, string $terminalName, int $locationId): array
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `pos_terminals` (
                `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `terminal_id`  VARCHAR(50) NOT NULL UNIQUE,
                `name`         VARCHAR(100) NOT NULL,
                `location_id`  BIGINT UNSIGNED NOT NULL,
                `is_active`    BOOLEAN NOT NULL DEFAULT 1,
                `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at`  TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->prepare("
            INSERT INTO pos_terminals (terminal_id, name, location_id)
            VALUES (:tid, :name, :lid)
            ON DUPLICATE KEY UPDATE name = VALUES(name), location_id = VALUES(location_id), last_seen_at = NOW()
        ")->execute([':tid' => $terminalId, ':name' => $terminalName, ':lid' => $locationId]);

        return ['terminal_id' => $terminalId, 'name' => $terminalName, 'location_id' => $locationId];
    }

    public function listTerminals(): array
    {
        return $this->db->query("SELECT * FROM pos_terminals ORDER BY registered_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Exchanges ─────────────────────────────────────────────────────────

    public function processExchange(
        int $sessionId,
        int $cashierId,
        array $returnItems,
        array $newItems,
        string $paymentMethod = 'cash',
        ?float $cashReceived = null,
        ?string $customerName = 'Walk-in Customer',
        ?string $customerPhone = '0000000000'
    ): array {
        $session = $this->getSession($sessionId);
        if (!$session || $session['closed_at'] !== null) {
            throw new RuntimeException('Active POS session required');
        }

        $locationId = (int)$session['location_id'];
        $year = date('Y');

        $this->db->beginTransaction();
        try {
            // 1. Process return items (restock back to shop location)
            $totalReturnCredit = 0.00;
            foreach ($returnItems as $rItem) {
                $pid = (int)$rItem['product_id'];
                $qty = (int)$rItem['quantity'];
                $price = (float)$rItem['unit_price'];
                $vid = !empty($rItem['variant_id']) ? (int)$rItem['variant_id'] : null;
                $reason = $rItem['reason'] ?? 'Customer exchange';

                $itemCredit = $qty * $price;
                $totalReturnCredit += $itemCredit;

                // Add physical stock back to shop
                $this->db->prepare("
                    UPDATE inventory_stock
                    SET quantity = quantity + :qty, updated_at = NOW()
                    WHERE product_id = :pid AND location_id = :lid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
                ")->execute([':qty' => $qty, ':pid' => $pid, ':lid' => $locationId, ':vid' => $vid, ':vid2' => $vid]);

                // Record return movement
                $cost = (float)$this->db->query("SELECT cost_price FROM products WHERE id = {$pid}")->fetchColumn();
                $this->db->prepare("
                    INSERT INTO stock_movements (product_id, variant_id, location_id, movement_type, quantity, unit_cost, notes, created_by)
                    VALUES (:pid, :vid, :lid, 'return', :qty, :cost, :notes, :by)
                ")->execute([
                    ':pid'   => $pid,
                    ':vid'   => $vid,
                    ':lid'   => $locationId,
                    ':qty'   => $qty,
                    ':cost'  => $cost,
                    ':notes' => "POS Exchange return: {$reason}",
                    ':by'    => $cashierId,
                ]);
            }

            // 2. Process new purchase items via orderService
            $order = $this->orderService->createOrder([
                'channel'                => 'pos',
                'customer_name'          => $customerName ?: 'Walk-in Customer',
                'customer_phone'         => $customerPhone ?: '0000000000',
                'items'                  => $newItems,
                'discount_amount'        => 0.00,
                'fulfilment_location_id' => $locationId,
                'delivery_method'        => 'shop_collection',
            ], $cashierId);

            $newOrderTotal = (float)$order['total_amount'];
            $netDifference = $newOrderTotal - $totalReturnCredit;

            $changeGiven = 0.00;
            if ($netDifference > 0) {
                // Customer must pay difference
                if ($paymentMethod === 'cash') {
                    if ($cashReceived === null || $cashReceived < $netDifference) {
                        throw new RuntimeException("Cash received (LKR {$cashReceived}) is less than exchange balance (LKR {$netDifference})");
                    }
                    $changeGiven = $cashReceived - $netDifference;
                }
            }

            $txCount = (int)$this->db->query("SELECT COUNT(*) FROM pos_transactions WHERE reference_number LIKE 'CC-POS-{$year}-%'")->fetchColumn() + 1;
            $posRef  = sprintf('CC-POS-%s-%06d', $year, $txCount);
            $uuid    = Uuid::v4();
            $exchangeRef = 'EXC-' . bin2hex(random_bytes(4));

            $stmtTx = $this->db->prepare("
                INSERT INTO pos_transactions (
                    uuid, reference_number, session_id, order_id, cashier_id, location_id,
                    transaction_type, return_amount, exchange_ref, payment_method, cash_received, change_given
                ) VALUES (
                    :uuid, :ref, :sid, :oid, :cid, :lid,
                    'exchange', :ret_amt, :exc_ref, :pmethod, :crecv, :cchange
                )
            ");
            $stmtTx->execute([
                ':uuid'     => $uuid,
                ':ref'      => $posRef,
                ':sid'      => $sessionId,
                ':oid'      => (int)$order['id'],
                ':cid'      => $cashierId,
                ':lid'      => $locationId,
                ':ret_amt'  => $totalReturnCredit,
                ':exc_ref'  => $exchangeRef,
                ':pmethod'  => $paymentMethod,
                ':crecv'    => $cashReceived,
                ':cchange'  => $changeGiven,
            ]);

            $txId = (int)$this->db->lastInsertId();

            $this->db->commit();

            return [
                'transaction_id'       => $txId,
                'pos_reference'        => $posRef,
                'exchange_reference'   => $exchangeRef,
                'return_credit'        => $totalReturnCredit,
                'new_purchase_total'   => $newOrderTotal,
                'net_amount_due'       => max(0.00, $netDifference),
                'store_credit_refund'  => $netDifference < 0 ? abs($netDifference) : 0.00,
                'cash_received'        => $cashReceived,
                'change_given'         => $changeGiven,
                'order'                => $order,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
