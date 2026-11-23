<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Payments;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Notifications\NotificationQueueService;
use CeylonCues\Services\Payments\PaymentService;
use Exception;

/**
 * Payments & Bank Transfer REST API Controller
 */
final class PaymentController
{
    private PaymentService $payments;
    private NotificationQueueService $notifications;

    public function __construct()
    {
        $this->payments = new PaymentService();
        $this->notifications = new NotificationQueueService();
    }

    /**
     * POST /api/v1/payments/bank-transfer/submit
     * Customer submits manual transfer evidence slip.
     */
    public function submitBankTransfer(Request $request): void
    {
        $data = $request->getBody();

        // Support resolving payment_id from order_id if not directly supplied
        if (empty($data['payment_id']) && !empty($data['order_id'])) {
            $db = \CeylonCues\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1");
            $stmt->execute([':order_id' => $data['order_id']]);
            $paymentId = $stmt->fetchColumn();
            if ($paymentId) {
                $data['payment_id'] = (int)$paymentId;
            }
        }

        if (empty($data['account_holder'])) {
            $data['account_holder'] = $data['customer_name'] ?? 'Account Holder';
        }

        if (empty($data['transfer_date'])) {
            $data['transfer_date'] = date('Y-m-d');
        }

        $v = Validator::validate($data, [
            'payment_id'         => 'required|integer',
            'bank_name'          => 'required|string',
            'account_holder'     => 'required|string',
            'transfer_reference' => 'required|string',
            'transfer_date'      => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $submission = $this->payments->submitBankTransferProof(
                (int)$data['payment_id'],
                $data['bank_name'],
                $data['account_holder'],
                $data['transfer_reference'],
                $data['transfer_date'],
                $data['evidence_file_path'] ?? null,
                $data['evidence_file_name'] ?? null
            );

            // Queue WhatsApp notification to owner
            $this->notifications->queueEvent('payment.bank_transfer_submitted', [
                'order_ref'     => $submission['order_reference'],
                'customer_name' => $submission['customer_name'],
                'amount'        => number_format((float)$submission['total_amount'], 2),
                'bank_name'     => $submission['bank_name'],
                'transfer_ref'  => $submission['transfer_reference'],
            ]);

            Response::json($submission, 'Bank transfer details submitted. Pending manual verification.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/payments/bank-transfers/pending (ERP Finance / Owner)
     */
    public function listPending(Request $request): void
    {
        $list = $this->payments->listPendingBankTransfers();
        Response::json($list, 'Pending bank transfer submissions retrieved');
    }

    /**
     * POST /api/v1/payments/bank-transfers/{id}/verify (ERP Finance / Owner)
     */
    public function verifyBankTransfer(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $isApproved = null;
        if (isset($data['approved'])) {
            $isApproved = filter_var($data['approved'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($data['status'])) {
            $isApproved = strtolower(trim((string)$data['status'])) === 'approved';
        }

        if ($isApproved === null) {
            Response::validationError(['approved' => ['The approval decision (status or approved) is required.']]);
        }

        try {
            $result = $this->payments->verifyBankTransfer(
                $id,
                $isApproved,
                (int)$user['id'],
                $data['rejection_reason'] ?? null
            );

            $msg = $isApproved ? 'Bank transfer approved and marked as paid.' : 'Bank transfer rejected.';
            Response::json($result, $msg);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/payments/refunds (ERP)
     */
    public function refund(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'order_id'      => 'required|integer',
            'refund_amount' => 'required|numeric',
            'reason'        => 'required|string',
            'refund_method' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $refund = $this->payments->processRefund(
                (int)$data['order_id'],
                (float)$data['refund_amount'],
                $data['reason'],
                $data['refund_method'],
                (int)$user['id']
            );
            Response::json($refund, 'Refund processed successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/payments/bank-transfers/{id}/proof (Customer Owner or ERP Finance/Owner)
     *
     * Supports both Authorization: Bearer <token> header AND ?token=<token> query param
     * so the ERP can embed the proof image directly as <img src="...?token=...">
     */
    public function getProof(Request $request): void
    {
        $id   = (int)$request->getRouteParam('id');
        $user = $request->getUser();

        // Fall back to ?token= query param when no Bearer header was sent
        if (!$user) {
            $queryToken = $_GET['token'] ?? '';
            if ($queryToken !== '') {
                try {
                    $authService = new \CeylonCues\Services\Auth\AuthService();
                    $user = $authService->validateSession($queryToken);
                } catch (\Throwable) {
                    $user = null;
                }
            }
        }

        if (!$user) {
            Response::unauthorized();
        }

        try {
            $filePath = $this->payments->getProofFilePath($id, (int)$user['id'], $user['role']);
            $mime = mime_content_type($filePath) ?: 'application/octet-stream';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($filePath));
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($filePath);
            exit;
        } catch (Exception $e) {
            Response::error($e->getMessage(), 403);
        }
    }
}
