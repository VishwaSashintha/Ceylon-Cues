<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Orders;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Orders\ReturnService;
use Exception;

final class ReturnController
{
    private ReturnService $returnService;

    public function __construct()
    {
        $this->returnService = new ReturnService();
    }

    public function index(Request $request): void
    {
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $filters = [
            'status' => $request->getParam('status'),
            'search' => $request->getParam('search'),
        ];

        $result = $this->returnService->listReturns($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $result['current_page'], $result['per_page'], 'Return requests retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $return = $this->returnService->getReturn(is_numeric($id) ? (int)$id : $id);
        if (!$return) {
            Response::notFound('Return request not found');
        }
        Response::json($return, 'Return request details retrieved');
    }

    public function store(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'order_id' => 'required|integer',
            'items'    => 'required|array',
            'reason'   => 'required|min:3',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $return = $this->returnService->createReturn(
                (int)$data['order_id'],
                $data['items'],
                $data['reason'],
                $user ? (int)$user['id'] : null
            );
            Response::created($return, 'Return request submitted');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approve(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();

        try {
            $return = $this->returnService->approveReturn($id, (int)$user['id']);
            Response::json($return, 'Return request approved');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function reject(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['rejection_reason' => 'required|min:3']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $return = $this->returnService->rejectReturn($id, $data['rejection_reason'], (int)$user['id']);
            Response::json($return, 'Return request rejected');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function receive(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $locationId = (int)($data['location_id'] ?? 1);
        $restoreStock = !isset($data['restore_stock']) || (bool)$data['restore_stock'];

        try {
            $return = $this->returnService->receiveReturnedGoods(
                $id,
                $locationId,
                (int)$user['id'],
                $restoreStock,
                $data['notes'] ?? null
            );
            Response::json($return, 'Returned goods received and inventory processed');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
