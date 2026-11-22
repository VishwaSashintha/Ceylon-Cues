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
}
