<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Pos;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Pos\PosService;
use Exception;

/**
 * Shop POS Controller
 */
final class PosController
{
    private PosService $pos;

    public function __construct()
    {
        $this->pos = new PosService();
    }

    /**
     * POST /api/v1/pos/sessions/open
     */
    public function openSession(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'location_id'     => 'required|integer',
            'opening_balance' => 'numeric',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $session = $this->pos->openSession(
                (int)$user['id'],
                (int)$data['location_id'],
                (float)($data['opening_balance'] ?? 0.00),
                $data['notes'] ?? null
            );
            Response::created($session, 'POS register session opened');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/pos/sessions/{id}/close
     */
    public function closeSession(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, ['closing_balance' => 'required|numeric']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $session = $this->pos->closeSession($id, (float)$data['closing_balance'], $data['notes'] ?? null);
            Response::json($session, 'POS register session closed');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/pos/sales
     */
    public function processSale(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'session_id'     => 'required|integer',
            'items'          => 'required|array',
            'payment_method' => 'required|in:cash,card,bank_transfer',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $sale = $this->pos->processSale(
                (int)$data['session_id'],
                (int)$user['id'],
                $data['items'],
                $data['payment_method'],
                isset($data['cash_received']) ? (float)$data['cash_received'] : null,
                $data['card_reference'] ?? null,
                $data['customer_name'] ?? 'Walk-in Customer',
                $data['customer_phone'] ?? '0000000000',
                (float)($data['discount_amount'] ?? 0.00)
            );
            Response::json($sale, 'POS transaction completed successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
