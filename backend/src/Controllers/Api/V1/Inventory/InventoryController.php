<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Inventory;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Inventory\InventoryService;
use CeylonCues\Services\Inventory\StockAllocationService;
use CeylonCues\Services\Inventory\StockTransferService;
use Exception;

/**
 * Inventory & Stock Transfer REST API Controller
 */
final class InventoryController
{
    private InventoryService $inventory;
    private StockTransferService $transfer;
    private StockAllocationService $allocation;

    public function __construct()
    {
        $this->inventory = new InventoryService();
        $this->transfer = new StockTransferService();
        $this->allocation = new StockAllocationService();
    }

    /**
     * GET /api/v1/inventory/locations
     */
    public function locations(Request $request): void
    {
        $locations = $this->inventory->getLocations();
        Response::json($locations, 'Inventory locations retrieved');
    }

    /**
     * GET /api/v1/inventory/stock
     */
    public function stock(Request $request): void
    {
        $locId = $request->getParam('location_id') ? (int)$request->getParam('location_id') : null;
        $filters = [
            'search'         => $request->getParam('search'),
            'low_stock_only' => $request->getParam('low_stock_only'),
        ];
        $stock = $this->inventory->getStockLevels($locId, $filters);
        Response::json($stock, 'Stock balances retrieved');
    }

    /**
     * POST /api/v1/inventory/adjustments
     */
    public function adjust(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'product_id'      => 'required|integer',
            'location_id'     => 'required|integer',
            'quantity_change' => 'required|integer',
            'reason'          => 'required|string',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            $result = $this->inventory->adjustStock(
                (int)$data['product_id'],
                (int)$data['location_id'],
                (int)$data['quantity_change'],
                $data['reason'],
                $data['notes'] ?? null,
                $user ? (int)$user['id'] : null,
                !empty($data['variant_id']) ? (int)$data['variant_id'] : null
            );
            Response::json($result, 'Stock adjusted successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/inventory/transfers
     */
    public function listTransfers(Request $request): void
    {
        $filters = ['status' => $request->getParam('status')];
        $transfers = $this->transfer->listTransfers($filters);
        Response::json($transfers, 'Stock transfers retrieved');
    }

    /**
     * POST /api/v1/inventory/transfers
     */
    public function createTransfer(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'from_location_id' => 'required|integer',
            'to_location_id'   => 'required|integer',
            'items'            => 'required|array',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            $transfer = $this->transfer->createTransfer(
                (int)$data['from_location_id'],
                (int)$data['to_location_id'],
                $data['items'],
                (int)$user['id'],
                $data['notes'] ?? null
            );
            Response::created($transfer, 'Stock transfer requested successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/inventory/transfers/{id}/complete
     */
    public function completeTransfer(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();

        try {
            $transfer = $this->transfer->completeTransfer($id, (int)$user['id']);
            Response::json($transfer, 'Stock transfer completed. Physical inventories updated.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/inventory/allocations/{productId}
     */
    public function showAllocation(Request $request): void
    {
        $pid = (int)$request->getRouteParam('id');
        $alloc = $this->allocation->getAllocation($pid);
        Response::json($alloc, 'Website stock allocation retrieved');
    }

    /**
     * PUT /api/v1/inventory/allocations/{productId}
     */
    public function updateAllocation(Request $request): void
    {
        $pid = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, ['allocated_quantity' => 'required|integer']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $res = $this->allocation->setAllocation(
            $pid,
            (int)$data['allocated_quantity'],
            (bool)($data['is_visible_online'] ?? true),
            (bool)($data['allow_backorder'] ?? false),
            isset($data['source_location_id']) ? (int)$data['source_location_id'] : null
        );

        Response::json($res, 'Online stock allocation updated');
    }

    /**
     * GET /api/v1/inventory/movements
     */
    public function movements(Request $request): void
    {
        $filters = [
            'product_id'    => $request->getParam('product_id'),
            'location_id'   => $request->getParam('location_id'),
            'movement_type' => $request->getParam('movement_type'),
        ];
        $movements = $this->inventory->getMovements($filters);
        Response::json($movements, 'Stock movement audit trail retrieved');
    }

    public function counts(Request $request): void
    {
        $locId = $request->getParam('location_id') ? (int)$request->getParam('location_id') : null;
        $counts = $this->inventory->listStockCounts($locId);
        Response::json($counts, 'Stock counts retrieved');
    }

    public function showCount(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $count = $this->inventory->getStockCount(is_numeric($id) ? (int)$id : $id);
        if (!$count) {
            Response::notFound('Stock count record not found');
        }
        Response::json($count, 'Stock count details retrieved');
    }

    public function createCount(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['location_id' => 'required|integer']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $count = $this->inventory->createStockCount(
                (int)$data['location_id'],
                (int)$user['id'],
                $data['notes'] ?? null
            );
            Response::created($count, 'Physical stock count initiated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function recordCountItem(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'product_id'       => 'required|integer',
            'counted_quantity' => 'required|integer',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $count = $this->inventory->recordCountItem(
                $id,
                (int)$data['product_id'],
                (int)$data['counted_quantity'],
                !empty($data['variant_id']) ? (int)$data['variant_id'] : null,
                $data['notes'] ?? null
            );
            Response::json($count, 'Count item recorded');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function reconcileCount(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();

        try {
            $count = $this->inventory->reconcileStockCount($id, (int)$user['id']);
            Response::json($count, 'Stock count reconciled and physical inventories synchronized');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
