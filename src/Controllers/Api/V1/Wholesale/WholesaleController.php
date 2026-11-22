<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Wholesale;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Notifications\NotificationQueueService;
use CeylonCues\Services\Wholesale\WholesaleService;
use Exception;

/**
 * Wholesale B2B REST API Controller
 */
final class WholesaleController
{
    private WholesaleService $wholesale;
    private NotificationQueueService $notifications;

    public function __construct()
    {
        $this->wholesale = new WholesaleService();
        $this->notifications = new NotificationQueueService();
    }

    /**
     * POST /api/v1/wholesale/enquiry (Public / Customer)
     */
    public function submitEnquiry(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'company_name' => 'required|string|max:150',
            'contact_name' => 'required|string|max:100',
            'email'        => 'required|email|max:255',
            'phone'        => 'required|string|max:30',
            'message'      => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $enquiry = $this->wholesale->submitEnquiry($data, $user ? (int)$user['id'] : null);

            // Queue notification to owner
            $this->notifications->queueEvent('wholesale.enquiry_received', [
                'ref'          => $enquiry['reference_number'],
                'company_name' => $enquiry['company_name'],
                'contact_name' => $enquiry['contact_name'],
                'phone'        => $enquiry['phone'],
                'timestamp'    => date('Y-m-d H:i:s'),
            ]);

            Response::created($enquiry, 'Wholesale enquiry submitted. Our team will contact you shortly.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/wholesale/enquiries (ERP)
     */
    public function listEnquiries(Request $request): void
    {
        $list = $this->wholesale->listEnquiries();
        Response::json($list, 'Wholesale enquiries retrieved');
    }

    /**
     * GET /api/v1/wholesale/pricing-tiers/{productId}
     */
    public function tiers(Request $request): void
    {
        $pid = (int)$request->getRouteParam('id');
        $tiers = $this->wholesale->getProductTiers($pid);
        Response::json($tiers, 'Wholesale pricing tiers retrieved');
    }

    /**
     * POST /api/v1/wholesale/pricing-tiers/{productId} (ERP)
     */
    public function setTier(Request $request): void
    {
        $pid = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'min_quantity' => 'required|integer|min:2',
            'price'        => 'required|numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $this->wholesale->setPriceTier($pid, (int)$data['min_quantity'], (float)$data['price']);
        Response::json(null, 'Wholesale pricing tier saved');
    }
}
