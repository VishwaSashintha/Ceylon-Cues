<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Checkout;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Delivery\DeliveryService;
use CeylonCues\Services\Marketing\MarketingService;
use CeylonCues\Services\Notifications\NotificationQueueService;
use CeylonCues\Services\Orders\CartService;
use CeylonCues\Services\Orders\OrderService;
use CeylonCues\Services\Payments\PaymentService;
use Exception;

/**
 * Checkout REST API Controller
 */
final class CheckoutController
{
    private CartService $cart;
    private OrderService $orders;
    private DeliveryService $delivery;
    private MarketingService $marketing;
    private PaymentService $payments;
    private NotificationQueueService $notifications;

    public function __construct()
    {
        $this->cart = new CartService();
        $this->orders = new OrderService();
        $this->delivery = new DeliveryService();
        $this->marketing = new MarketingService();
        $this->payments = new PaymentService();
        $this->notifications = new NotificationQueueService();
    }

    /**
     * POST /api/v1/checkout/calculate
     * Preview totals with district shipping and discount code before placing order.
     */
    public function calculate(Request $request): void
    {
        $data = $request->getBody();
        $subtotal = (float)($data['subtotal'] ?? 0.00);
        $district = $data['district'] ?? 'Colombo';
        $method = $data['delivery_method'] ?? 'home_delivery';
        $couponCode = $data['coupon_code'] ?? null;
        $user = $request->getUser();

        $deliveryInfo = $this->delivery->calculateDelivery($district, $subtotal, $method);
        $deliveryCharge = (float)$deliveryInfo['delivery_charge'];

        $discountAmount = 0.00;
        $discountInfo = null;

        if (!empty($couponCode)) {
            try {
                $discountInfo = $this->marketing->validateCoupon($couponCode, $subtotal, $user ? (int)$user['id'] : null);
                $discountAmount = (float)$discountInfo['discount_amount'];
                if (!empty($discountInfo['free_delivery'])) {
                    $deliveryCharge = 0.00;
                }
            } catch (Exception $e) {
                Response::error($e->getMessage(), 400);
            }
        }

        $totalAmount = max(0.00, ($subtotal - $discountAmount) + $deliveryCharge);

        Response::json([
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_info'   => $discountInfo,
            'delivery_charge' => $deliveryCharge,
            'delivery_info'   => $deliveryInfo,
            'total_amount'    => $totalAmount,
            'currency'        => 'LKR',
        ], 'Checkout calculation successful');
    }

    /**
     * POST /api/v1/checkout/place-order
     * Place an order from cart.
     */
    public function placeOrder(Request $request): void
    {
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'customer_name'   => 'required|string|max:150',
            'customer_phone'  => 'required|string|max:30',
            'payment_method'  => 'required|string',
            'delivery_method' => 'required|string',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        $cartContents = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
        if (empty($cartContents['items'])) {
            Response::error('Shopping cart is empty', 400);
        }

        try {
            $subtotal = (float)$cartContents['subtotal'];
            $district = $data['district'] ?? 'Colombo';
            $delMethod = $data['delivery_method'];
            $deliveryInfo = $this->delivery->calculateDelivery($district, $subtotal, $delMethod);
            $deliveryCharge = (float)$deliveryInfo['delivery_charge'];

            $discountAmount = 0.00;
            $discountId = null;

            if (!empty($data['coupon_code'])) {
                $discountRes = $this->marketing->validateCoupon($data['coupon_code'], $subtotal, $user ? (int)$user['id'] : null);
                $discountAmount = (float)$discountRes['discount_amount'];
                $discountId = (int)$discountRes['discount_id'];
                if (!empty($discountRes['free_delivery'])) {
                    $deliveryCharge = 0.00;
                }
            }

            // Convert cart items to order items format
            $orderItems = [];
            foreach ($cartContents['items'] as $item) {
                $orderItems[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'quantity'   => $item['quantity'],
                ];
            }

            $order = $this->orders->createOrder([
                'channel'                  => 'online',
                'customer_name'            => $data['customer_name'],
                'customer_phone'           => $data['customer_phone'],
                'customer_email'           => $data['customer_email'] ?? ($user['email'] ?? null),
                'user_id'                  => $user ? (int)$user['id'] : null,
                'items'                    => $orderItems,
                'delivery_method'          => $delMethod,
                'delivery_zone_id'         => $deliveryInfo['zone_id'],
                'delivery_charge'          => $deliveryCharge,
                'discount_code'            => $data['coupon_code'] ?? null,
                'discount_amount'          => $discountAmount,
                'shipping_address'         => $data['shipping_address'] ?? null,
                'notes'                    => $data['notes'] ?? null,
            ], $user ? (int)$user['id'] : null);

            $orderId = (int)$order['id'];

            // Record discount usage if applied
            if ($discountId) {
                $this->marketing->recordUsage($discountId, $orderId, $discountAmount, $user ? (int)$user['id'] : null);
            }

            // Process payment dispatch
            $idempotency = $request->getHeader('idempotency-key') ?? bin2hex(random_bytes(16));
            $paymentResult = $this->payments->processPayment($orderId, $data['payment_method'], $idempotency);

            // Clear cart
            $this->cart->clearCart($cartContents['cart_id']);

            // Queue WhatsApp notification to owner
            $this->notifications->queueEvent('order.created', [
                'order_ref'       => $order['reference_number'],
                'customer_name'   => $order['customer_name'],
                'customer_phone'  => $order['customer_phone'],
                'item_count'      => count($orderItems),
                'subtotal'        => number_format($subtotal, 2),
                'delivery_charge' => number_format($deliveryCharge, 2),
                'total_amount'    => number_format((float)$order['total_amount'], 2),
                'payment_method'  => $data['payment_method'],
                'payment_status'  => $paymentResult['status'],
                'delivery_method' => $delMethod,
            ]);

            Response::created([
                'order'   => $order,
                'payment' => $paymentResult,
            ], 'Order placed successfully! Thank you for choosing Ceylon Cues.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
