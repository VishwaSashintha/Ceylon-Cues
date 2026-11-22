<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Suppliers;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Suppliers\SupplierService;
use Exception;

final class SupplierController
{
    private SupplierService $supplierService;

    public function __construct()
    {
        $this->supplierService = new SupplierService();
    }

    public function index(Request $request): void
    {
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $filters = [
            'search'    => $request->getParam('search'),
            'is_active' => $request->getParam('is_active'),
        ];

        $result = $this->supplierService->listSuppliers($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $result['current_page'], $result['per_page'], 'Suppliers retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $supplier = $this->supplierService->getSupplier(is_numeric($id) ? (int)$id : $id);
        if (!$supplier) {
            Response::notFound('Supplier not found');
        }
        Response::json($supplier, 'Supplier retrieved');
    }

    public function store(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'company_name' => 'required|min:2',
            'email'        => 'email',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $supplier = $this->supplierService->createSupplier($data);
            Response::created($supplier, 'Supplier registered successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function update(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        try {
            $supplier = $this->supplierService->updateSupplier($id, $data);
            Response::json($supplier, 'Supplier updated successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function destroy(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->supplierService->deleteSupplier($id);
        Response::json(['id' => $id], 'Supplier removed');
    }

    public function purchaseHistory(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $history = $this->supplierService->getSupplierPurchaseHistory($id);
        Response::json($history, 'Supplier purchase history retrieved');
    }

    public function recordPayment(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'amount'         => 'required|numeric',
            'payment_method' => 'required|in:bank_transfer,cheque,cash',
            'payment_date'   => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $payment = $this->supplierService->recordPayment(
                $id,
                (float)$data['amount'],
                $data['payment_method'],
                $data['payment_date'],
                !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null,
                $data['payment_reference'] ?? null,
                $data['notes'] ?? null,
                $user ? (int)$user['id'] : null
            );
            Response::created($payment, 'Supplier payment recorded successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function listPayments(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $payments = $this->supplierService->listPayments($id);
        Response::json($payments, 'Supplier payments retrieved');
    }

    public function recordReturn(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'location_id' => 'required|integer',
            'reason'      => 'required|string',
            'items'       => 'required|array',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $return = $this->supplierService->recordPurchaseReturn(
                $id,
                (int)$data['location_id'],
                $data['reason'],
                $data['items'],
                !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null,
                $user ? (int)$user['id'] : null
            );
            Response::created($return, 'Purchase return to supplier created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function listReturns(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $returns = $this->supplierService->listPurchaseReturns($id);
        Response::json($returns, 'Supplier purchase returns retrieved');
    }
}
