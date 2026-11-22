<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Pos;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Pos\PosService;
use Exception;

final class PosController
{
    private PosService $pos;

    public function __construct()
    {
        $this->pos = new PosService();
    }

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

    public function closeSession(Request $request): void
    {
        $id   = (int)$request->getRouteParam('id');
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
                (float)($data['discount_amount'] ?? 0.00),
                $data
            );
            Response::json($sale, 'POS transaction completed successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function listSessions(Request $request): void
    {
        $limit   = min(100, max(1, (int)$request->getParam('limit', 50)));
        $filters = [
            'cashier_id'  => $request->getParam('cashier_id'),
            'location_id' => $request->getParam('location_id'),
        ];
        $sessions = $this->pos->listSessions($filters, $limit);
        Response::json($sessions, 'POS register sessions retrieved');
    }

    public function showSession(Request $request): void
    {
        $id      = (int)$request->getRouteParam('id');
        $session = $this->pos->getSession($id);
        if (!$session) {
            Response::notFound('POS session not found');
        }
        Response::json($session, 'POS session details retrieved');
    }

    public function listTransactions(Request $request): void
    {
        $page    = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));
        $filters = [
            'session_id'     => $request->getParam('session_id'),
            'payment_method' => $request->getParam('payment_method'),
            'search'         => $request->getParam('search'),
        ];
        $result = $this->pos->listTransactions($filters, $page, $perPage);
        Response::paginated($result['data'], $result['total'], $result['current_page'], $result['per_page'], 'POS transactions retrieved');
    }

    public function showTransaction(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $tx = $this->pos->getTransaction(is_numeric($id) ? (int)$id : $id);
        if (!$tx) {
            Response::notFound('POS transaction record not found');
        }
        Response::json($tx, 'POS transaction receipt details retrieved');
    }

    public function dailySummary(Request $request): void
    {
        $date  = $request->getParam('date');
        $locId = $request->getParam('location_id') ? (int)$request->getParam('location_id') : null;
        $summary = $this->pos->getDailySummary($date, $locId);
        Response::json($summary, 'POS daily sales summary retrieved');
    }

    public function syncOffline(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['sales' => 'required|array']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $result = $this->pos->syncOfflineSales($data['sales'], (int)$user['id']);
            Response::json($result, 'Offline sales sync completed');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function verifyPin(Request $request): void
    {
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'pin'        => 'required|string',
            'session_id' => 'required|integer',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $ok = $this->pos->verifyManagerPin($data['pin'], (int)$data['session_id']);
            Response::json(['authorised' => $ok], $ok ? 'Manager PIN verified' : 'Invalid PIN');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function cashMovement(Request $request): void
    {
        $id   = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'type'   => 'required|in:in,out',
            'amount' => 'required|numeric',
            'reason' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $this->pos->recordCashMovement($id, $data['type'], (float)$data['amount'], $data['reason']);
            Response::json(['session_id' => $id], 'Cash movement recorded');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function showFloat(Request $request, int $id): void
    {
        try {
            $float = $this->pos->getCashFloat($id);
            Response::json($float, 'Cash float details retrieved');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 404);
        }
    }
}
