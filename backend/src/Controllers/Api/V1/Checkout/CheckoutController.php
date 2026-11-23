<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Checkout;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Auth\OtpService;
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
    private OtpService $otp;

    public function __construct()
    {
        $this->cart = new CartService();
        $this->orders = new OrderService();
        $this->delivery = new DeliveryService();
        $this->marketing = new MarketingService();
        $this->payments = new PaymentService();
        $this->notifications = new NotificationQueueService();
        $this->otp = new OtpService();
    }

    /**
     * POST /api/v1/checkout/request-otp
     * Issue a 6-digit OTP to the authenticated customer's email before order placement.
     */
    public function requestOtp(Request $request): void
    {
        $user = $request->getUser();

        if (!$user) {
            Response::error('Authentication required to place an order.', 401);
        }

        $data = $request->getBody();

        // Build a canonical payload string to bind the OTP to the specific order
        $payloadArr = [
            'delivery_method' => $data['delivery_method'] ?? '',
            'items'           => $data['items'] ?? [],
            'payment_method'  => $data['payment_method'] ?? '',
        ];
        ksort($payloadArr); // sort keys canonically (PHP has no JSON_SORT_KEYS constant)
        $payloadString = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);

        $email = $user['email'] ?? '';
        if (empty($email)) {
            Response::error('Customer email address not found. Please update your profile.', 400);
        }

        try {
            $code = $this->otp->issue((int)$user['id'], $email, $payloadString);

            $devMode = (($_ENV['APP_ENV'] ?? 'local') !== 'production');

            Response::json([
                'email_sent' => true,
                'email_hint' => substr($email, 0, 3) . str_repeat('*', max(0, strpos($email, '@') - 3)) . substr($email, strpos($email, '@')),
                'expires_in' => 600,
                // Include OTP in response body in dev/local mode only — never in production
                'dev_otp'    => $devMode ? $code : null,
            ], 'Verification code sent to your email address.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
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
     * Place an order. Authenticated customers must supply a valid OTP code.
     * Guest checkout (no user) bypasses OTP but is limited to COD orders.
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

        // OTP verification for authenticated customers (required for bank transfer; optional for COD)
        if ($user && !empty($data['otp_code'])) {
            $payloadArr2 = [
                'delivery_method' => $data['delivery_method'] ?? '',
                'items'           => $data['items'] ?? [],
                'payment_method'  => $data['payment_method'] ?? '',
            ];
            ksort($payloadArr2);
            $payloadString = json_encode($payloadArr2, JSON_UNESCAPED_UNICODE);

            try {
                $this->otp->verify((int)$user['id'], (string)$data['otp_code'], $payloadString);
            } catch (Exception $e) {
                Response::error($e->getMessage(), 422);
            }
        } elseif ($user && ($data['payment_method'] === 'bank_transfer' || $data['payment_method'] === 'bank-transfer')) {
            Response::error('An order verification code is required for bank transfer orders. Please request a code and check your email.', 422);
        }

        $cartContents = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
        $orderItems = [];
        $subtotal = 0.00;

        if (!empty($cartContents['items'])) {
            $subtotal = (float)$cartContents['subtotal'];
            foreach ($cartContents['items'] as $item) {
                $orderItems[] = [
                    'product_id' => (int)$item['product_id'],
                    'variant_id' => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                    'quantity'   => (int)$item['quantity'],
                ];
            }
        } elseif (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity'])) {
                    $orderItems[] = [
                        'product_id' => (int)$item['product_id'],
                        'variant_id' => !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                        'quantity'   => (int)$item['quantity'],
                    ];
                }
            }
            if (!empty($orderItems)) {
                $productIds = array_unique(array_column($orderItems, 'product_id'));
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $db = \CeylonCues\Core\Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id, retail_price FROM products WHERE id IN ($placeholders)");
                $stmt->execute(array_values($productIds));
                $prices = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
                foreach ($orderItems as $it) {
                    $subtotal += ((float)($prices[$it['product_id']] ?? 0)) * $it['quantity'];
                }
            }
        }

        if (empty($orderItems)) {
            Response::error('Shopping cart is empty', 400);
        }

        try {
            $district = $data['shipping_district'] ?? ($data['district'] ?? 'Colombo');
            $delMethod = match ($data['delivery_method'] ?? 'home_delivery') {
                'home', 'home_delivery' => 'home_delivery',
                'shop', 'pickup', 'store_pickup' => 'store_pickup',
                default => 'home_delivery'
            };
            $paymentMethod = match ($data['payment_method'] ?? 'bank_transfer') {
                'cod', 'cash_on_delivery' => 'cash_on_delivery',
                'card' => 'card',
                default => 'bank_transfer'
            };

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
            $paymentResult = $this->payments->processPayment($orderId, $paymentMethod, $idempotency);

            // Clear cart if session cart exists
            if (!empty($cartContents['cart_id'])) {
                $this->cart->clearCart($cartContents['cart_id']);
            }

            // Queue WhatsApp notification to owner
            $refNumber = $order['reference_number'] ?? "CC-ORD-{$orderId}";
            $this->notifications->queueEvent('order.created', [
                'order_ref'       => $refNumber,
                'customer_name'   => $order['customer_name'],
                'customer_phone'  => $order['customer_phone'],
                'item_count'      => count($orderItems),
                'subtotal'        => number_format($subtotal, 2),
                'delivery_charge' => number_format($deliveryCharge, 2),
                'total_amount'    => number_format((float)($order['total_amount'] ?? 0), 2),
                'payment_method'  => $paymentMethod,
                'payment_status'  => $paymentResult['status'] ?? 'pending',
                'delivery_method' => $delMethod,
            ]);

            Response::created([
                'order'           => $order,
                'order_id'        => $orderId,
                'order_number'    => $refNumber,
                'order_reference' => $refNumber,
                'total_amount'    => $order['total_amount'] ?? ($subtotal - $discountAmount + $deliveryCharge),
                'payment'         => $paymentResult,
            ], 'Order placed successfully! Thank you for choosing Ceylon Cues.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
