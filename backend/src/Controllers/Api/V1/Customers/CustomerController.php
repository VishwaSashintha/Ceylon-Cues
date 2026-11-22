<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Customers;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Customers\CustomerService;
use Exception;

final class CustomerController
{
    private CustomerService $customerService;

    public function __construct()
    {
        $this->customerService = new CustomerService();
    }

    public function index(Request $request): void
    {
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $filters = [
            'search'    => $request->getParam('search'),
            'is_active' => $request->getParam('is_active'),
        ];

        $result = $this->customerService->listCustomers($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $result['current_page'], $result['per_page'], 'Customers retrieved');
    }

    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $customer = $this->customerService->getCustomer(is_numeric($id) ? (int)$id : $id);
        if (!$customer) {
            Response::notFound('Customer not found');
        }
        Response::json($customer, 'Customer details retrieved');
    }

    public function update(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        try {
            $customer = $this->customerService->updateCustomer($id, $data);
            Response::json($customer, 'Customer updated successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function addresses(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $addresses = $this->customerService->getAddresses($id);
        Response::json($addresses, 'Customer addresses retrieved');
    }

    public function addAddress(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'recipient_name' => 'required',
            'phone'          => 'required',
            'address_line1'  => 'required',
            'city'           => 'required',
            'district'       => 'required',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $addresses = $this->customerService->addAddress($id, $data);
            Response::created($addresses, 'Customer address added');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
