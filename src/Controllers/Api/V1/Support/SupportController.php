<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Support;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Support\SupportService;
use Exception;

/**
 * Customer Support REST API Controller
 */
final class SupportController
{
    private SupportService $support;

    public function __construct()
    {
        $this->support = new SupportService();
    }

    /**
     * GET /api/v1/support/tickets
     */
    public function index(Request $request): void
    {
        $user = $request->getUser();
        $filters = ['status' => $request->getParam('status')];

        if ($user && $user['role'] === 'customer') {
            $filters['user_id'] = $user['id'];
        }

        $tickets = $this->support->listTickets($filters);
        Response::json($tickets, 'Support tickets retrieved');
    }

    /**
     * GET /api/v1/support/tickets/{id}
     */
    public function show(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $ticket = $this->support->getTicket($id);
        if (!$ticket) {
            Response::notFound('Ticket not found');
        }

        $user = $request->getUser();
        if ($user && $user['role'] === 'customer' && (int)$ticket['user_id'] !== (int)$user['id']) {
            Response::forbidden('Access denied');
        }

        Response::json($ticket, 'Ticket details retrieved');
    }

    /**
     * POST /api/v1/support/tickets
     */
    public function store(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'subject' => 'required|string|max:200',
            'message' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $ticket = $this->support->createTicket($data, $user ? (int)$user['id'] : null);
            Response::created($ticket, 'Support ticket submitted successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/support/tickets/{id}/reply
     */
    public function reply(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['message' => 'required|string']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $senderName = $user ? "{$user['first_name']} {$user['last_name']}" : ($data['sender_name'] ?? 'Customer');
        $isInternal = (bool)($data['is_internal'] ?? false);

        if ($isInternal && (!$user || $user['role'] === 'customer')) {
            $isInternal = false;
        }

        try {
            $ticket = $this->support->replyToTicket($id, $data['message'], $senderName, $user ? (int)$user['id'] : null, $isInternal);
            Response::json($ticket, 'Reply posted successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
