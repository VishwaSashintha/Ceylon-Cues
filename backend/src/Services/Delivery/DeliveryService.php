<?php

declare(strict_types=1);

namespace CeylonCues\Services\Delivery;

use CeylonCues\Core\Database;
use PDO;

/**
 * Delivery Management Service
 *
 * Computes district-based shipping rates, zone lookups, free delivery thresholds,
 * and collection options.
 */
final class DeliveryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all active delivery zones.
     */
    public function getZones(): array
    {
        $stmt = $this->db->query("SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        $zones = $stmt->fetchAll();
        foreach ($zones as &$z) {
            $z['districts'] = json_decode($z['districts'] ?? '[]', true);
            $z['delivery_charge'] = (float)$z['delivery_charge'];
            $z['free_delivery_threshold'] = $z['free_delivery_threshold'] ? (float)$z['free_delivery_threshold'] : null;
        }
        return $zones;
    }

    /**
     * Calculate delivery charge for a district and order amount.
     */
    public function calculateDelivery(string $district, float $subtotal, string $method = 'home_delivery'): array
    {
        if (in_array($method, ['shop_collection', 'warehouse_dispatch'], true)) {
            return [
                'delivery_method' => $method,
                'delivery_charge' => 0.00,
                'free_delivery'   => true,
                'zone_name'       => 'Self Collection',
                'zone_id'         => null,
                'estimated_days'  => 0,
            ];
        }

        $zones = $this->getZones();
        $matchedZone = null;

        $targetDistrict = strtolower(trim($district));

        foreach ($zones as $zone) {
            foreach ($zone['districts'] as $d) {
                if (strtolower(trim($d)) === $targetDistrict) {
                    $matchedZone = $zone;
                    break 2;
                }
            }
        }

        // Fallback to outer zone if district not found
        if (!$matchedZone && !empty($zones)) {
            $matchedZone = end($zones);
        }

        $baseCharge = $matchedZone ? (float)$matchedZone['delivery_charge'] : 500.00;
        $threshold = $matchedZone['free_delivery_threshold'] ?? 15000.00;
        $isFree = $threshold && $subtotal >= $threshold;

        $finalCharge = $isFree ? 0.00 : $baseCharge;

        return [
            'delivery_method' => $method,
            'district'        => $district,
            'zone_id'         => $matchedZone['id'] ?? null,
            'zone_name'       => $matchedZone['name'] ?? 'Standard Sri Lanka Delivery',
            'base_charge'     => $baseCharge,
            'delivery_charge' => $finalCharge,
            'free_delivery'   => $isFree,
            'threshold'       => $threshold,
            'estimated_days'  => $matchedZone['estimated_days'] ?? 3,
        ];
    }

    public function getZone(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM delivery_zones WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $zone = $stmt->fetch();
        if ($zone) {
            $zone['districts'] = json_decode($zone['districts'] ?? '[]', true);
            $zone['delivery_charge'] = (float)$zone['delivery_charge'];
            $zone['free_delivery_threshold'] = $zone['free_delivery_threshold'] ? (float)$zone['free_delivery_threshold'] : null;
        }
        return $zone ?: null;
    }

    public function createZone(array $data): array
    {
        $districtsJson = is_array($data['districts'] ?? null) ? json_encode($data['districts']) : ($data['districts'] ?? '[]');
        $stmt = $this->db->prepare("
            INSERT INTO delivery_zones (name, districts, delivery_charge, free_delivery_threshold, estimated_days, is_active)
            VALUES (:name, :districts, :charge, :threshold, :days, :active)
        ");
        $stmt->execute([
            ':name'      => $data['name'],
            ':districts' => $districtsJson,
            ':charge'    => (float)$data['delivery_charge'],
            ':threshold' => isset($data['free_delivery_threshold']) ? (float)$data['free_delivery_threshold'] : null,
            ':days'      => (int)($data['estimated_days'] ?? 3),
            ':active'    => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);

        return $this->getZone((int)$this->db->lastInsertId());
    }

    public function updateZone(int $id, array $data): array
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = "name = :name";
            $params[':name'] = $data['name'];
        }
        if (isset($data['districts'])) {
            $fields[] = "districts = :districts";
            $params[':districts'] = is_array($data['districts']) ? json_encode($data['districts']) : $data['districts'];
        }
        if (isset($data['delivery_charge'])) {
            $fields[] = "delivery_charge = :charge";
            $params[':charge'] = (float)$data['delivery_charge'];
        }
        if (array_key_exists('free_delivery_threshold', $data)) {
            $fields[] = "free_delivery_threshold = :threshold";
            $params[':threshold'] = $data['free_delivery_threshold'] !== null ? (float)$data['free_delivery_threshold'] : null;
        }
        if (isset($data['estimated_days'])) {
            $fields[] = "estimated_days = :days";
            $params[':days'] = (int)$data['estimated_days'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = :active";
            $params[':active'] = (int)$data['is_active'];
        }

        if (!empty($fields)) {
            $this->db->prepare("UPDATE delivery_zones SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id")->execute($params);
        }

        return $this->getZone($id);
    }

    public function deleteZone(int $id): bool
    {
        return $this->db->prepare("UPDATE delivery_zones SET is_active = 0, updated_at = NOW() WHERE id = :id")->execute([':id' => $id]);
    }

    public function updateOrderFulfillment(int $orderId, array $data, int $updatedBy): array
    {
        $carrier = $data['carrier_name'] ?? 'Local Courier';
        $trackingNumber = $data['carrier_tracking_number'] ?? $data['tracking_number'] ?? null;
        $status = $data['status'] ?? null;

        $updates = [
            'carrier_name = :carrier',
            'carrier_tracking_number = :track',
            'tracking_number = :track',
            'updated_at = NOW()',
        ];
        $params = [
            ':carrier' => $carrier,
            ':track'   => $trackingNumber,
            ':id'      => $orderId,
        ];

        if ($status) {
            $updates[] = 'status = :status';
            $params[':status'] = $status;
        }

        $setSql = implode(', ', $updates);
        $this->db->prepare("UPDATE orders SET {$setSql} WHERE id = :id")->execute($params);

        $stmtOrder = $this->db->prepare("SELECT * FROM orders WHERE id = :id");
        $stmtOrder->execute([':id' => $orderId]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        // Send customer email if shipped
        if (($status === 'shipped' || !empty($trackingNumber)) && !empty($order['customer_email'])) {
            try {
                $mailer = new \CeylonCues\Services\Mail\MailService();
                $mailer->sendOrderShipped(
                    $order['customer_email'],
                    $order['customer_name'] ?? 'Valued Customer',
                    $order['reference_number'] ?? (string)$orderId,
                    $carrier,
                    $trackingNumber ?? 'N/A'
                );
            } catch (\Throwable) {
                // Email logging handled inside MailService
            }
        }

        return $order ?: [];
    }
}
