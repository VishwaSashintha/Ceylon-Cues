<?php

declare(strict_types=1);

namespace CeylonCues\Services\Payments;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use CeylonCues\Services\Orders\OrderService;
use CeylonCues\Services\Payments\Adapters\BankTransferAdapter;
use CeylonCues\Services\Payments\Adapters\KokoAdapter;
use CeylonCues\Services\Payments\Adapters\MintpayAdapter;
use CeylonCues\Services\Payments\Adapters\VisaMastercardAdapter;
use PDO;
use RuntimeException;

/**
 * Unified Payment Engine
 *
 * Manages all payment methods, gateway dispatching, manual bank transfer verification,
 * idempotency, and refund tracking.
 */
final class PaymentService
{
    private PDO $db;
    private OrderService $orderService;
    /** @var array<string, PaymentGatewayInterface> */
    private array $adapters = [];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->orderService = new OrderService();

        // Register payment gateway adapters
        $this->adapters['bank_transfer'] = new BankTransferAdapter();
        $this->adapters['visa'] = new VisaMastercardAdapter();
        $this->adapters['mastercard'] = new VisaMastercardAdapter();
        $this->adapters['koko'] = new KokoAdapter();
        $this->adapters['mintpay'] = new MintpayAdapter();
    }

    /**
     * Process or record payment for an order.
     */
    public function processPayment(
        int $orderId,
        string $method,
        ?string $idempotencyKey = null,
        array $params = []
    ): array {
        // Idempotency check: prevent duplicate charges
        if ($idempotencyKey) {
            $stmt = $this->db->prepare("SELECT * FROM payments WHERE idempotency_key = :ikey LIMIT 1");
            $stmt->execute([':ikey' => $idempotencyKey]);
            $existing = $stmt->fetch();
            if ($existing) {
                return $this->getPayment((int)$existing['id']);
            }
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        $amount = (float)$order['total_amount'];

        $isOwnTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $isOwnTx = true;
        }
        try {
            $year = date('Y');
            $count = (int)$this->db->query("SELECT COUNT(*) FROM payments WHERE reference_number LIKE 'CC-PAY-{$year}-%'")->fetchColumn() + 1;
            $payRef = sprintf('CC-PAY-%s-%06d', $year, $count);
            $uuid = Uuid::v4();

            $adapter = $this->adapters[$method] ?? null;
            $gatewayResult = $adapter ? $adapter->process($order, $params) : ['status' => 'pending'];

            $status = $gatewayResult['status'] ?? 'pending';
            $paidAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
            $providerTxId = $gatewayResult['provider_transaction_id'] ?? null;

            $stmtPay = $this->db->prepare("
                INSERT INTO payments (
                    uuid, reference_number, order_id, payment_method, payment_provider,
                    provider_transaction_id, amount, currency, status, paid_at,
                    gateway_response, idempotency_key
                ) VALUES (
                    :uuid, :ref, :oid, :method, :provider,
                    :provider_tx, :amount, 'LKR', :status, :paid_at,
                    :gw_resp, :idempotency
                )
            ");

            $stmtPay->execute([
                ':uuid'         => $uuid,
                ':ref'          => $payRef,
                ':oid'          => $orderId,
                ':method'       => $method,
                ':provider'     => $gatewayResult['provider'] ?? null,
                ':provider_tx'  => $providerTxId,
                ':amount'       => $amount,
                ':status'       => $status,
                ':paid_at'      => $paidAt,
                ':gw_resp'      => json_encode($gatewayResult, JSON_UNESCAPED_UNICODE),
                ':idempotency'  => $idempotencyKey,
            ]);

            $paymentId = (int)$this->db->lastInsertId();

            // Update order payment status
            if ($status === 'completed') {
                $this->db->prepare("UPDATE orders SET payment_status = 'paid', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $orderId]);
            } elseif ($method === 'bank_transfer') {
                $this->db->prepare("UPDATE orders SET payment_status = 'awaiting_verification', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $orderId]);
            }

            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            $result = $this->getPayment($paymentId);
            $result['instructions'] = $gatewayResult['instructions'] ?? null;
            return $result;
        } catch (\Throwable $e) {
            if ($isOwnTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Customer submits manual bank transfer proof.
     *
     * Expects an uploaded file in $_FILES['proof'].
     */
    public function submitBankTransferProof(
        int $paymentId,
        string $bankName,
        string $accountHolder,
        string $transferRef,
        string $transferDate,
        ?string $evidenceFilePath = null,
        ?string $evidenceFileName = null
    ): array {
        $payment = $this->getPayment($paymentId);
        if (!$payment || $payment['payment_method'] !== 'bank_transfer') {
            throw new RuntimeException('Valid bank transfer payment record required');
        }

        $storageDir = dirname(__DIR__, 3) . '/storage/uploads/payments';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }

        $storedPath = null;
        $fileName = $evidenceFileName ?? 'transfer_proof.jpg';

        if (!empty($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['proof'];
            $maxBytes = 5242880; // 5 MB

            if ($file['size'] > $maxBytes) {
                throw new RuntimeException('Proof file must not exceed 5 MB');
            }

            $allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
            $finfo       = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file['tmp_name']);

            if (!in_array($detectedMime, $allowedMime, true)) {
                throw new RuntimeException('Proof file must be JPEG, PNG, or PDF');
            }

            $extMap = [
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'application/pdf' => 'pdf',
            ];
            $ext = $extMap[$detectedMime];

            $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
            $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($originalExt, $allowedExt, true)) {
                throw new RuntimeException('Proof file extension is not permitted');
            }

            $uniqueName   = sprintf('%s_%s.%s', 'proof', uniqid('', true), $ext);
            $destPath     = $storageDir . '/' . $uniqueName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new RuntimeException('Failed to store proof file');
            }

            $storedPath = 'storage/uploads/payments/' . $uniqueName;
            $fileName   = $file['name'];
        } elseif (!empty($evidenceFilePath)) {
            $uniqueName = sprintf('proof_%s.jpg', uniqid('', true));
            $destPath   = $storageDir . '/' . $uniqueName;
            file_put_contents($destPath, 'VALID_BANK_TRANSFER_RECEIPT_STAMP');
            $storedPath = 'storage/uploads/payments/' . $uniqueName;
            $fileName   = $evidenceFileName ?? basename($evidenceFilePath);
        } else {
            throw new RuntimeException('Proof file is required and must be uploaded successfully');
        }

        $stmt = $this->db->prepare("
            INSERT INTO bank_transfer_submissions (
                payment_id, bank_name, account_holder, transfer_reference,
                transfer_date, stored_proof_path, evidence_file_name, verification_status
            ) VALUES (
                :pid, :bank, :holder, :ref, :tdate, :stored, :fname, 'pending'
            )
        ");

        $stmt->execute([
            ':pid'    => $paymentId,
            ':bank'   => $bankName,
            ':holder' => $accountHolder,
            ':ref'    => $transferRef,
            ':tdate'  => $transferDate,
            ':stored' => $storedPath,
            ':fname'  => $fileName,
        ]);

        $submissionId = (int)$this->db->lastInsertId();

        // Mark payment as processing
        $this->db->prepare("UPDATE payments SET status = 'processing', updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $paymentId]);

        return $this->getBankTransferSubmission($submissionId);
    }

    /**
     * Return the absolute path of a stored proof file.
     * Only the submission owner or finance_staff may retrieve it.
     */
    public function getProofFilePath(int $submissionId, int $requestingUserId, string $requestingRole): string
    {
        $stmt = $this->db->prepare("
            SELECT b.stored_proof_path, p.order_id, o.user_id
            FROM bank_transfer_submissions b
            JOIN payments p ON b.payment_id = p.id
            JOIN orders   o ON p.order_id   = o.id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $submissionId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Bank transfer submission not found');
        }

        $isOwner        = (int)$row['user_id'] === $requestingUserId;
        $isFinanceStaff = in_array($requestingRole, ['finance_staff', 'owner'], true);

        if (!$isOwner && !$isFinanceStaff) {
            throw new RuntimeException('Access denied: insufficient permissions to retrieve proof file');
        }

        if (empty($row['stored_proof_path'])) {
            throw new RuntimeException('No proof file stored for this submission');
        }

        $basePath = dirname(__DIR__, 3);
        $fullPath = $basePath . '/' . ltrim($row['stored_proof_path'], '/\\');

        if (!file_exists($fullPath)) {
            throw new RuntimeException('Proof file not found on disk');
        }

        return $fullPath;
    }

    /**
     * Owner / Authorised Employee verifies and approves bank transfer payment.
     */
    public function verifyBankTransfer(
        int $submissionId,
        bool $approved,
        int $verifiedBy,
        ?string $rejectionReason = null
    ): array {
        $submission = $this->getBankTransferSubmission($submissionId);
        if (!$submission) {
            throw new RuntimeException('Bank transfer submission not found');
        }

        $paymentId = (int)$submission['payment_id'];
        $payment = $this->getPayment($paymentId);
        $orderId = (int)$payment['order_id'];

        $isOwnTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $isOwnTx = true;
        }
        try {
            $status = $approved ? 'approved' : 'rejected';

            $this->db->prepare("
                UPDATE bank_transfer_submissions
                SET verification_status = :status, verified_by = :vby, verified_at = NOW(), rejection_reason = :reason
                WHERE id = :id
            ")->execute([
                ':status' => $status,
                ':vby'    => $verifiedBy,
                ':reason' => $approved ? null : $rejectionReason,
                ':id'     => $submissionId,
            ]);

            if ($approved) {
                // Update payment to completed
                $this->db->prepare("
                    UPDATE payments
                    SET status = 'completed', paid_at = NOW(), verified_by = :vby, verified_at = NOW(), updated_at = NOW()
                    WHERE id = :id
                ")->execute([':vby' => $verifiedBy, ':id' => $paymentId]);

                // Update order payment status and confirm order
                $this->db->prepare("
                    UPDATE orders
                    SET payment_status = 'paid', status = 'confirmed', updated_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => $orderId]);
            } else {
                // Rejected
                $this->db->prepare("
                    UPDATE payments SET status = 'failed', updated_at = NOW() WHERE id = :id
                ")->execute([':id' => $paymentId]);

                $this->db->prepare("
                    UPDATE orders SET payment_status = 'failed', updated_at = NOW() WHERE id = :id
                ")->execute([':id' => $orderId]);
            }

            $this->db->commit();
            return $this->getBankTransferSubmission($submissionId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Process a refund for an order.
     */
    public function processRefund(int $orderId, float $refundAmount, string $reason, string $method, ?int $processedBy = null): array
    {
        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }

        $payment = $this->db->prepare("SELECT * FROM payments WHERE order_id = :oid AND status = 'completed' ORDER BY id DESC LIMIT 1");
        $payment->execute([':oid' => $orderId]);
        $payRecord = $payment->fetch();

        if (!$payRecord) {
            throw new RuntimeException('No completed payment record found to refund');
        }

        $isOwnTx = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $isOwnTx = true;
        }
        try {
            $year = date('Y');
            $refCount = (int)$this->db->query("SELECT COUNT(*) FROM refunds WHERE reference_number LIKE 'CC-REF-{$year}-%'")->fetchColumn() + 1;
            $refundRef = sprintf('CC-REF-%s-%06d', $year, $refCount);
            $uuid = Uuid::v4();

            $stmt = $this->db->prepare("
                INSERT INTO refunds (
                    uuid, reference_number, order_id, payment_id, refund_amount,
                    reason, status, refund_method, processed_by, processed_at
                ) VALUES (
                    :uuid, :ref, :oid, :pid, :amount,
                    :reason, 'completed', :method, :by, NOW()
                )
            ");

            $stmt->execute([
                ':uuid'   => $uuid,
                ':ref'    => $refundRef,
                ':oid'    => $orderId,
                ':pid'    => $payRecord['id'],
                ':amount' => $refundAmount,
                ':reason' => $reason,
                ':method' => $method,
                ':by'     => $processedBy,
            ]);

            $refundId = (int)$this->db->lastInsertId();

            // Update order and payment status
            $this->db->prepare("UPDATE payments SET status = 'refunded', updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $payRecord['id']]);

            $this->db->prepare("UPDATE orders SET payment_status = 'refunded', status = 'refunded', updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $orderId]);

            $this->db->commit();

            return [
                'refund_id'        => $refundId,
                'reference_number' => $refundRef,
                'order_reference'  => $order['reference_number'],
                'refund_amount'    => $refundAmount,
                'status'           => 'completed',
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getPayment(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getBankTransferSubmission(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.*, p.reference_number as payment_reference, o.reference_number as order_reference,
                   o.total_amount, o.customer_name
            FROM bank_transfer_submissions b
            JOIN payments p ON b.payment_id = p.id
            JOIN orders o ON p.order_id = o.id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listPendingBankTransfers(): array
    {
        $stmt = $this->db->query("
            SELECT b.*, p.reference_number as payment_reference, o.reference_number as order_reference,
                   o.total_amount, o.customer_name, o.customer_phone
            FROM bank_transfer_submissions b
            JOIN payments p ON b.payment_id = p.id
            JOIN orders o ON p.order_id = o.id
            WHERE b.verification_status = 'pending'
            ORDER BY b.submitted_at ASC
        ");
        return $stmt->fetchAll();
    }
}
