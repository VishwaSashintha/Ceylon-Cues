<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Marketing;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Marketing\MarketingService;
use Exception;

/**
 * Marketing & Discount REST API Controller
 */
final class MarketingController
{
    private MarketingService $marketing;

    public function __construct()
    {
        $this->marketing = new MarketingService();
    }

    /**
     * POST /api/v1/marketing/validate-coupon (Customer)
     */
    public function validateCoupon(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'code'     => 'required|string',
            'subtotal' => 'required|numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            $res = $this->marketing->validateCoupon(
                $data['code'],
                (float)$data['subtotal'],
                $user ? (int)$user['id'] : null
            );
            Response::json($res, 'Coupon is valid');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/marketing/discounts (ERP)
     */
    public function index(Request $request): void
    {
        $discounts = $this->marketing->listDiscounts();
        Response::json($discounts, 'Discounts retrieved');
    }

    /**
     * POST /api/v1/marketing/discounts (ERP)
     */
    public function store(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'name'  => 'required|string|max:150',
            'type'  => 'required|in:percentage,fixed,free_delivery',
            'value' => 'required|numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $discount = $this->marketing->createDiscount($data, (int)$user['id']);
            Response::created($discount, 'Discount created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
