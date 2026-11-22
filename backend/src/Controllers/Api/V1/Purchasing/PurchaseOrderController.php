<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Purchasing;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Purchasing\PurchaseOrderService;
use Exception;

final class PurchaseOrderController
{
    private PurchaseOrderService $poService;

    public function __construct()
    {
        $this->poService = new PurchaseOrderService();
    }

    public function index(Request $request): void
    {
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $filters = [
            'supplier_id' => $request->getParam('supplier_id'),
            'status'      => $request->getParam('status'),
            'search'      => $request->getParam('search'),
        ];

        $result = $this->poService->listPurchaseOrders($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $result['current_page'], $result['per_page'], 'Purchase orders retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $po = $this->poService->getPurchaseOrder(is_numeric($id) ? (int)$id : $id);
        if (!$po) {
            Response::notFound('Purchase order not found');
        }
        Response::json($po, 'Purchase order details retrieved');
    }

    public function store(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'supplier_id' => 'required|integer',
            'items'       => 'required|array',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $po = $this->poService->createPurchaseOrder($data, (int)$user['id']);
            Response::created($po, 'Purchase order created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function updateStatus(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['status' => 'required']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $po = $this->poService->updatePurchaseOrderStatus($id, $data['status'], $data['notes'] ?? null, (int)$user['id']);
            Response::json($po, 'Purchase order status updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function receiveGoods(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'location_id' => 'required|integer',
            'items'       => 'required|array',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $result = $this->poService->receiveGoods(
                $id,
                (int)$data['location_id'],
                $data['items'],
                (int)$user['id'],
                $data['notes'] ?? null
            );
            Response::json($result, 'Goods received and inventory updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
