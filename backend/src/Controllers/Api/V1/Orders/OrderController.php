<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Orders;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Notifications\NotificationQueueService;
use CeylonCues\Services\Orders\OrderService;
use Exception;

/**
 * Order Management REST API Controller
 */
final class OrderController
{
    private OrderService $orders;
    private NotificationQueueService $notifications;

    public function __construct()
    {
        $this->orders = new OrderService();
        $this->notifications = new NotificationQueueService();
    }

    /**
     * GET /api/v1/orders
     */
    public function index(Request $request): void
    {
        $user = $request->getUser();
        $filters = [
            'channel' => $request->getParam('channel'),
            'status'  => $request->getParam('status'),
            'search'  => $request->getParam('search'),
        ];

        // Restrict customers to only seeing their own orders
        if ($user && $user['role'] === 'customer') {
            $filters['user_id'] = $user['id'];
        }

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));

        $result = $this->orders->listOrders($filters, $page, $perPage);
        Response::paginated($result['items'], $result['total'], $page, $perPage, 'Orders retrieved');
    }

    /**
     * GET /api/v1/orders/{id}
     */
    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $order = $this->orders->getOrder($id);

        if (!$order) {
            Response::notFound('Order not found');
        }

        $user = $request->getUser();
        if ($user && $user['role'] === 'customer' && (int)$order['user_id'] !== (int)$user['id']) {
            Response::forbidden('Access denied to this order');
        }

        Response::json($order, 'Order retrieved');
    }

    /**
     * POST /api/v1/orders
     */
    public function store(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'customer_name'  => 'required|string|max:150',
            'customer_phone' => 'required|string|max:30',
            'items'          => 'required|array',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            if ($user && empty($data['user_id'])) {
                $data['user_id'] = $user['id'];
            }

            $order = $this->orders->createOrder($data, $user ? (int)$user['id'] : null);

            // Queue WhatsApp notification to owner
            $this->notifications->queueEvent('order.created', [
                'order_ref'       => $order['reference_number'],
                'customer_name'   => $order['customer_name'],
                'customer_phone'  => $order['customer_phone'],
                'item_count'      => count($order['items']),
                'subtotal'        => number_format((float)$order['subtotal'], 2),
                'delivery_charge' => number_format((float)$order['delivery_charge'], 2),
                'total_amount'    => number_format((float)$order['total_amount'], 2),
                'payment_method'  => $data['payment_method'] ?? 'pending',
                'payment_status'  => $order['payment_status'],
                'delivery_method' => $order['delivery_method'],
            ]);

            Response::created($order, 'Order placed successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/v1/orders/{id}/status (ERP)
     */
    public function updateStatus(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        $v = Validator::validate($data, ['status' => 'required|string']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            $order = $this->orders->updateOrderStatus($id, $data['status'], $data['notes'] ?? null, $user ? (int)$user['id'] : null);
            Response::json($order, 'Order status updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
