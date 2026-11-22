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
}
