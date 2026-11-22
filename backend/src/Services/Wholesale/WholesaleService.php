<?php

declare(strict_types=1);

namespace CeylonCues\Services\Wholesale;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use CeylonCues\Services\Orders\OrderService;
use PDO;
use RuntimeException;

/**
 * Wholesale Management Service
 *
 * Manages B2B enquiries, tiered price lists, and high-volume wholesale orders.
 */
final class WholesaleService
{
    private PDO $db;
    private OrderService $orderService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->orderService = new OrderService();
    }

    /**
     * Submit a B2B wholesale enquiry.
     */
    public function submitEnquiry(array $data, ?int $userId = null): array
    {
        $year = date('Y');
        $count = (int)$this->db->query("SELECT COUNT(*) FROM wholesale_enquiries WHERE reference_number LIKE 'CC-WENQ-{$year}-%'")->fetchColumn() + 1;
        $refNumber = sprintf('CC-WENQ-%s-%06d', $year, $count);
        $uuid = Uuid::v4();

        $stmt = $this->db->prepare("
            INSERT INTO wholesale_enquiries (
                uuid, reference_number, user_id, company_name, contact_name, email, phone, message, status
            ) VALUES (
                :uuid, :ref, :uid, :company, :contact, :email, :phone, :msg, 'new'
            )
        ");

        $stmt->execute([
            ':uuid'    => $uuid,
            ':ref'     => $refNumber,
            ':uid'     => $userId,
            ':company' => trim($data['company_name']),
            ':contact' => trim($data['contact_name']),
            ':email'   => strtolower(trim($data['email'])),
            ':phone'   => trim($data['phone']),
            ':msg'     => trim($data['message']),
        ]);

        return $this->getEnquiry((int)$this->db->lastInsertId());
    }

    public function getEnquiry(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM wholesale_enquiries WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $enq = $stmt->fetch();
        return $enq ?: null;
    }

    public function listEnquiries(): array
    {
        $stmt = $this->db->query("SELECT * FROM wholesale_enquiries ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Set wholesale pricing tier for bulk purchase.
     */
    public function setPriceTier(int $productId, int $minQuantity, float $price): void
    {
        $this->db->prepare("
            INSERT INTO wholesale_price_tiers (product_id, min_quantity, price)
            VALUES (:pid, :qty, :price)
            ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
        ")->execute([':pid' => $productId, ':qty' => $minQuantity, ':price' => $price]);
    }

    public function getProductTiers(int $productId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM wholesale_price_tiers WHERE product_id = :pid ORDER BY min_quantity ASC");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }

    public function approveWholesaleEnquiry(
        int $enquiryId,
        float $creditLimit,
        int $paymentTermsDays,
        int $approvedByUserId
    ): array {
        $enquiry = $this->getEnquiry($enquiryId);
        if (!$enquiry) {
            throw new RuntimeException("Wholesale enquiry #{$enquiryId} not found.");
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE wholesale_enquiries
                SET status = 'approved', approved_by = :approver, updated_at = NOW()
                WHERE id = :id
            ")->execute([':approver' => $approvedByUserId, ':id' => $enquiryId]);

            if (!empty($enquiry['user_id'])) {
                $this->db->prepare("
                    UPDATE users
                    SET credit_limit = :credit, payment_terms_days = :terms, updated_at = NOW()
                    WHERE id = :uid
                ")->execute([
                    ':credit' => $creditLimit,
                    ':terms'  => $paymentTermsDays,
                    ':uid'    => (int)$enquiry['user_id'],
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getEnquiry($enquiryId);
    }

    public function getCustomerStatement(
        int $customerId,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $where  = ['o.user_id = :cid', "o.channel = 'wholesale'"];
        $params = [':cid' => $customerId];

        if ($fromDate !== null) {
            $where[]          = 'DATE(o.created_at) >= :from';
            $params[':from']  = $fromDate;
        }
        if ($toDate !== null) {
            $where[]        = 'DATE(o.created_at) <= :to';
            $params[':to']  = $toDate;
        }

        $whereClause = implode(' AND ', $where);

        $orders = $this->db->prepare("
            SELECT o.id, o.reference_number, o.status, o.payment_status,
                   o.total_amount, o.created_at
            FROM orders o
            WHERE {$whereClause}
            ORDER BY o.created_at DESC
        ");
        $orders->execute($params);
        $orderRows = $orders->fetchAll();

        $paymentsStmt = $this->db->prepare("
            SELECT p.id, p.order_id, p.amount, p.status, p.payment_method, p.paid_at, p.created_at
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE o.user_id = :cid AND o.channel = 'wholesale'
            ORDER BY p.created_at DESC
        ");
        $paymentsStmt->execute([':cid' => $customerId]);
        $payments = $paymentsStmt->fetchAll();

        $totalInvoiced = array_sum(array_column($orderRows, 'total_amount'));
        $totalPaid     = array_sum(
            array_map(
                fn($p) => $p['status'] === 'paid' ? (float)$p['amount'] : 0.0,
                $payments
            )
        );

        return [
            'customer_id'        => $customerId,
            'from_date'          => $fromDate,
            'to_date'            => $toDate,
            'orders'             => $orderRows,
            'payments'           => $payments,
            'total_invoiced'     => round($totalInvoiced, 2),
            'total_paid'         => round($totalPaid, 2),
            'outstanding_balance' => round($totalInvoiced - $totalPaid, 2),
        ];
    }
}
