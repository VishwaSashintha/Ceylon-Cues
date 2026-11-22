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

    /**
     * GET /api/v1/orders/track?token={tracking_token} (Public Order Tracking)
     */
    public function publicTrack(Request $request): void
    {
        $token = $request->getQueryParam('token');
        if (empty($token)) {
            Response::error('Tracking token is required', 400);
        }

        $order = $this->orders->getOrder($token);
        if (!$order || $order['status'] === 'cancelled') {
            Response::error('Order not found or tracking token invalid', 404);
        }

        $safeItems = array_map(function ($item) {
            return [
                'product_name' => $item['product_name'] ?? 'Item',
                'quantity'     => (int)($item['quantity'] ?? 1),
            ];
        }, $order['items'] ?? []);

        $trackingInfo = [
            'reference_number'        => $order['reference_number'],
            'status'                  => $order['status'],
            'carrier_name'            => $order['carrier_name'] ?? 'Ceylon Cues Logistics',
            'carrier_tracking_number' => $order['carrier_tracking_number'] ?? null,
            'created_at'              => $order['created_at'],
            'delivery_method'         => $order['delivery_method'] ?? 'standard',
            'items'                   => $safeItems,
        ];

        Response::json($trackingInfo, 'Tracking details retrieved');
    }

    /**
     * GET /api/v1/orders/{id}/tax-invoice (Tax Invoice Data)
     */
    public function taxInvoice(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        try {
            $data = $this->orders->getTaxInvoiceData($id);
            // Verify permission
            $isStaff = in_array($user['role'] ?? '', ['owner', 'shop_manager', 'finance_staff'], true);
            $isCustomer = (int)($data['order']['user_id'] ?? 0) === (int)$user['id'];
            if (!$isStaff && !$isCustomer) {
                Response::forbidden();
            }

            Response::json($data, 'Tax invoice retrieved');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/v1/orders/{id}/invoice (Download Printable HTML Invoice)
     */
    public function downloadInvoice(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        try {
            $data = $this->orders->getTaxInvoiceData($id);
            $order = $data['order'];
            $items = $data['items'];

            $isStaff = in_array($user['role'] ?? '', ['owner', 'shop_manager', 'finance_staff'], true);
            $isCustomer = (int)($order['user_id'] ?? 0) === (int)$user['id'];
            if (!$isStaff && !$isCustomer) {
                Response::forbidden();
            }

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Invoice ' . htmlspecialchars($order['reference_number']) . '</title>';
            $html .= '<style>body{font-family:sans-serif;margin:40px;color:#222;}table{width:100%;border-collapse:collapse;margin:20px 0;}th,td{border:1px solid #ddd;padding:10px;text-align:left;}th{background:#f8f9fa;}.total{text-align:right;font-weight:bold;}</style></head><body>';
            $html .= '<h1>Ceylon Cues - Tax Invoice</h1>';
            $html .= '<p><strong>Invoice Number:</strong> ' . htmlspecialchars($data['tax_invoice_number']) . '<br>';
            $html .= '<strong>Order Ref:</strong> ' . htmlspecialchars($order['reference_number']) . '<br>';
            $html .= '<strong>Date:</strong> ' . htmlspecialchars($order['created_at']) . '</p>';
            $html .= '<table><thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>';
            foreach ($items as $item) {
                $html .= '<tr><td>' . htmlspecialchars($item['product_name']) . '</td><td>' . (int)$item['quantity'] . '</td><td>LKR ' . number_format((float)$item['unit_price'], 2) . '</td><td>LKR ' . number_format((float)$item['total_price'], 2) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '<p class="total">Subtotal: LKR ' . number_format((float)$order['subtotal'], 2) . '<br>';
            if ((float)($order['vat_amount'] ?? 0) > 0) {
                $html .= 'VAT (' . (float)$order['vat_percentage'] . '%): LKR ' . number_format((float)$order['vat_amount'], 2) . '<br>';
            }
            $html .= 'Grand Total: LKR ' . number_format((float)$order['total_amount'], 2) . '</p>';
            $html .= '<hr><p><small>Thank you for shopping with Ceylon Cues. Sri Lanka\'s Premier Cue Sports Provider.</small></p>';
            $html .= '</body></html>';

            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: inline; filename="Invoice-' . $order['reference_number'] . '.html"');
            echo $html;
            exit;
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
