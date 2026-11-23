<?php

declare(strict_types=1);

namespace CeylonCues\Services\Marketing;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Marketing & Discount Engine
 *
 * Validates promo coupons, handles percentage/fixed/free delivery discounts,
 * enforces first-order rules, stacking policies, and logs discount usage.
 */
final class MarketingService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Validate and apply a promotional discount code.
     */
    public function validateCoupon(string $code, float $orderSubtotal, ?int $userId = null): array
    {
        $code = strtoupper(trim($code));

        $stmt = $this->db->prepare("
            SELECT * FROM discounts 
            WHERE code = :code AND is_active = 1
              AND (start_date IS NULL OR start_date <= NOW())
              AND (end_date IS NULL OR end_date >= NOW())
            LIMIT 1
        ");
        $stmt->execute([':code' => $code]);
        $discount = $stmt->fetch();

        if (!$discount) {
            throw new RuntimeException('Coupon code is invalid or expired');
        }

        // Check global usage limit
        if ($discount['max_uses'] !== null && (int)$discount['used_count'] >= (int)$discount['max_uses']) {
            throw new RuntimeException('This coupon has reached its maximum usage limit');
        }

        // Check minimum order amount
        if ($discount['min_order_amount'] !== null && $orderSubtotal < (float)$discount['min_order_amount']) {
            throw new RuntimeException(sprintf('Minimum order subtotal of LKR %s required for this coupon', number_format((float)$discount['min_order_amount'], 2)));
        }

        // Check first-order only rule
        if (!empty($discount['is_first_order_only']) && $userId) {
            $pastOrders = (int)$this->db->query("SELECT COUNT(*) FROM orders WHERE user_id = {$userId} AND status != 'cancelled'")->fetchColumn();
            if ($pastOrders > 0) {
                throw new RuntimeException('This coupon code is only valid for your first order');
            }
        }

        // Check per-customer usage limit
        if ($userId && $discount['max_uses_per_customer']) {
            $userUses = (int)$this->db->query("SELECT COUNT(*) FROM discount_usage WHERE discount_id = {$discount['id']} AND user_id = {$userId}")->fetchColumn();
            if ($userUses >= (int)$discount['max_uses_per_customer']) {
                throw new RuntimeException('You have already redeemed this coupon');
            }
        }

        // Calculate discount amount
        $calculatedDiscount = 0.00;
        $isFreeDelivery = false;

        if ($discount['type'] === 'percentage') {
            $calculatedDiscount = ($orderSubtotal * (float)$discount['value']) / 100.0;
            if ($discount['max_discount_amount'] !== null) {
                $calculatedDiscount = min($calculatedDiscount, (float)$discount['max_discount_amount']);
            }
        } elseif ($discount['type'] === 'fixed') {
            $calculatedDiscount = min($orderSubtotal, (float)$discount['value']);
        } elseif ($discount['type'] === 'free_delivery') {
            $isFreeDelivery = true;
        }

        return [
            'valid'              => true,
            'discount_id'        => (int)$discount['id'],
            'code'               => $discount['code'],
            'name'               => $discount['name'],
            'type'               => $discount['type'],
            'value'              => (float)$discount['value'],
            'discount_amount'    => round($calculatedDiscount, 2),
            'free_delivery'      => $isFreeDelivery,
            'applies_to'         => $discount['applies_to'],
        ];
    }

    /**
     * Record discount usage after order placement.
     */
    public function recordUsage(int $discountId, int $orderId, float $discountAmount, ?int $userId = null): void
    {
        $this->db->prepare("
            INSERT INTO discount_usage (discount_id, user_id, order_id, discount_amount)
            VALUES (:did, :uid, :oid, :amount)
        ")->execute([':did' => $discountId, ':uid' => $userId, ':oid' => $orderId, ':amount' => $discountAmount]);

        $this->db->prepare("UPDATE discounts SET used_count = used_count + 1 WHERE id = :id")->execute([':id' => $discountId]);
    }

    /**
     * Create a new discount in ERP.
     */
    public function createDiscount(array $data, int $createdBy): array
    {
        $uuid = Uuid::v4();
        $code = !empty($data['code']) ? strtoupper(trim($data['code'])) : null;

        $stmt = $this->db->prepare("
            INSERT INTO discounts (
                uuid, code, name, type, value, applies_to, target_id,
                min_order_amount, max_discount_amount, max_uses, max_uses_per_customer,
                is_first_order_only, start_date, end_date, is_active, is_stackable, created_by
            ) VALUES (
                :uuid, :code, :name, :type, :val, :applies, :target,
                :min_order, :max_disc, :max_uses, :max_per_cust,
                :first_only, :start, :end, :active, :stack, :by
            )
        ");

        $stmt->execute([
            ':uuid'          => $uuid,
            ':code'          => $code,
            ':name'          => trim($data['name']),
            ':type'          => $data['type'],
            ':val'           => $data['value'],
            ':applies'       => $data['applies_to'] ?? 'order',
            ':target'        => $data['target_id'] ?? null,
            ':min_order'     => $data['min_order_amount'] ?? null,
            ':max_disc'      => $data['max_discount_amount'] ?? null,
            ':max_uses'      => $data['max_uses'] ?? null,
            ':max_per_cust'  => $data['max_uses_per_customer'] ?? 1,
            ':first_only'    => (int)($data['is_first_order_only'] ?? 0),
            ':start'         => $data['start_date'] ?? null,
            ':end'           => $data['end_date'] ?? null,
            ':active'        => (int)($data['is_active'] ?? 1),
            ':stack'         => (int)($data['is_stackable'] ?? 0),
            ':by'            => $createdBy,
        ]);

        return $this->getDiscount((int)$this->db->lastInsertId());
    }

    public function getDiscount(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM discounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listDiscounts(): array
    {
        $stmt = $this->db->query("SELECT * FROM discounts ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function deleteDiscount(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM discounts WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
