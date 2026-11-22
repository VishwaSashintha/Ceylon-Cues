<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Delivery;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Delivery\DeliveryService;

/**
 * Delivery REST API Controller
 */
final class DeliveryController
{
    private DeliveryService $delivery;

    public function __construct()
    {
        $this->delivery = new DeliveryService();
    }

    /**
     * GET /api/v1/delivery/zones
     */
    public function zones(Request $request): void
    {
        $zones = $this->delivery->getZones();
        Response::json($zones, 'Delivery zones retrieved');
    }

    /**
     * GET /api/v1/delivery/calculate
     */
    public function calculate(Request $request): void
    {
        $district = $request->getParam('district', 'Colombo');
        $subtotal = (float)$request->getParam('subtotal', 0.00);
        $method = $request->getParam('delivery_method', 'home_delivery');

        $calc = $this->delivery->calculateDelivery($district, $subtotal, $method);
        Response::json($calc, 'Delivery calculated');
    }
}
