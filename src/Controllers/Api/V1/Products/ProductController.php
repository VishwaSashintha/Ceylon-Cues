<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Products;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Products\ProductService;
use Exception;

/**
 * Product REST API Controller
 */
final class ProductController
{
    private ProductService $products;

    public function __construct()
    {
        $this->products = new ProductService();
    }

    /**
     * GET /api/v1/products
     */
    public function index(Request $request): void
    {
        $filters = [
            'category_id'  => $request->getParam('category_id'),
            'quality_tier' => $request->getParam('quality_tier'),
            'search'       => $request->getParam('search'),
            'is_published' => $request->getParam('is_published', 1),
            'is_active'    => 1,
        ];
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = min(100, max(1, (int)$request->getParam('per_page', 20)));

        $result = $this->products->listProducts($filters, $page, $perPage);
        Response::paginated($result['items'], $result['total'], $page, $perPage, 'Products retrieved');
    }

    /**
     * GET /api/v1/products/{id}
     */
    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $product = $this->products->getProduct($id);

        if (!$product) {
            Response::notFound('Product not found');
        }

        Response::json($product, 'Product details retrieved');
    }

    /**
     * POST /api/v1/products (ERP Owner/Inventory Staff)
     */
    public function store(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, [
            'name'         => 'required|string|max:255',
            'sku'          => 'required|string|max:100',
            'category_id'  => 'required|integer',
            'retail_price' => 'required|numeric',
        ]);

        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $user = $request->getUser();
            $product = $this->products->createProduct($data, $user ? (int)$user['id'] : null);
            Response::created($product, 'Product created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/v1/products/{id} (ERP)
     */
    public function update(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();

        try {
            $user = $request->getUser();
            $product = $this->products->updateProduct($id, $data, $user ? (int)$user['id'] : null);
            Response::json($product, 'Product updated successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/v1/products/{id} (ERP)
     */
    public function destroy(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->products->deleteProduct($id);
        Response::json(null, 'Product deleted successfully');
    }
}
