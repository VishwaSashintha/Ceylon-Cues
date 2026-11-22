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

    public function createZone(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'name'            => 'required',
            'delivery_charge' => 'required|numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $zone = $this->delivery->createZone($data);
            Response::created($zone, 'Delivery zone created');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function updateZone(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        try {
            $zone = $this->delivery->updateZone($id, $data);
            Response::json($zone, 'Delivery zone updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function destroyZone(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->delivery->deleteZone($id);
        Response::json(['id' => $id], 'Delivery zone deactivated');
    }

    public function updateFulfillment(Request $request): void
    {
        $orderId = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        try {
            $order = $this->delivery->updateOrderFulfillment($orderId, $data, (int)$user['id']);
            Response::json($order, 'Order fulfillment tracking updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
