<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Products;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Products\WishlistService;
use Exception;

final class WishlistController
{
    private WishlistService $wishlist;

    public function __construct()
    {
        $this->wishlist = new WishlistService();
    }

    public function index(Request $request): void
    {
        $user  = $request->getUser();
        $items = $this->wishlist->getWishlist((int)$user['id']);
        Response::json($items, 'Wishlist retrieved');
    }

    public function add(Request $request): void
    {
        $user = $request->getUser();
        $data = $request->getBody();

        $v = Validator::validate($data, ['product_id' => 'required|integer']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $this->wishlist->addToWishlist((int)$user['id'], (int)$data['product_id']);
            Response::json(null, 'Product added to wishlist');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function remove(Request $request, int $productId): void
    {
        $user = $request->getUser();

        try {
            $this->wishlist->removeFromWishlist((int)$user['id'], $productId);
            Response::json(null, 'Product removed from wishlist');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function notifyStock(Request $request, int $productId): void
    {
        $user = $request->getUser();

        try {
            $this->wishlist->requestStockNotification((int)$user['id'], $productId);
            Response::json(null, 'Stock notification request recorded');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
