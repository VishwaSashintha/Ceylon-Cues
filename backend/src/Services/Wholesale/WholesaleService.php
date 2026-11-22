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
}
