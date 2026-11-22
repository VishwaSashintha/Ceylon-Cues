<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Cart;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Orders\CartService;
use Exception;

/**
 * Cart REST API Controller
 */
final class CartController
{
    private CartService $cart;

    public function __construct()
    {
        $this->cart = new CartService();
    }

    /**
     * GET /api/v1/cart
     */
    public function show(Request $request): void
    {
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');

        $cart = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
        Response::json($cart, 'Cart retrieved');
    }

    /**
     * POST /api/v1/cart/items
     */
    public function addItem(Request $request): void
    {
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');
        $data = $request->getBody();

        $v = Validator::validate($data, [
            'product_id' => 'required|integer',
            'quantity'   => 'required|integer|min:1',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $cart = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
            $updated = $this->cart->addItem(
                $cart['cart_id'],
                (int)$data['product_id'],
                (int)$data['quantity'],
                !empty($data['variant_id']) ? (int)$data['variant_id'] : null
            );
            Response::json($updated, 'Item added to cart');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/v1/cart/items/{id}
     */
    public function updateItem(Request $request): void
    {
        $itemId = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');
        $data = $request->getBody();

        $v = Validator::validate($data, ['quantity' => 'required|integer']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $cart = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
            $updated = $this->cart->updateItemQuantity($cart['cart_id'], $itemId, (int)$data['quantity']);
            Response::json($updated, 'Cart updated');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/v1/cart/items/{id}
     */
    public function removeItem(Request $request): void
    {
        $itemId = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');

        $cart = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
        $updated = $this->cart->removeItem($cart['cart_id'], $itemId);
        Response::json($updated, 'Item removed from cart');
    }

    /**
     * DELETE /api/v1/cart
     */
    public function clear(Request $request): void
    {
        $user = $request->getUser();
        $sessionId = $request->getHeader('x-session-id') ?? $request->getParam('session_id');

        $cart = $this->cart->getOrCreateCart($user ? (int)$user['id'] : null, $sessionId);
        $this->cart->clearCart($cart['cart_id']);
        Response::json(null, 'Cart cleared');
    }
}
