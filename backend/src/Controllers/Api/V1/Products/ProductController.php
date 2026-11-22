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

    /**
     * POST /api/v1/products/{id}/images (ERP)
     */
    public function uploadImage(Request $request): void
    {
        $productId = (int)$request->getRouteParam('id');
        $product = $this->products->getProduct($productId);
        if (!$product) {
            Response::notFound('Product not found');
        }

        $files = $_FILES['image'] ?? null;
        $body = $request->getBody();

        if ($files && !empty($files['tmp_name']) && is_uploaded_file($files['tmp_name'])) {
            $ext = strtolower(pathinfo($files['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                Response::error('Invalid image type. Supported: JPG, PNG, WEBP, GIF', 400);
            }
            if ($files['size'] > 10 * 1024 * 1024) {
                Response::error('Image file too large (max 10MB)', 400);
            }

            $uploadDir = dirname(__DIR__, 5) . '/public/uploads/products';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $newFileName = 'prod_' . $productId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . '/' . $newFileName;

            if (move_uploaded_file($files['tmp_name'], $destination)) {
                $filePath = 'uploads/products/' . $newFileName;
                $img = $this->products->addImage($productId, $filePath, $newFileName);
                Response::created($img, 'Image uploaded successfully');
            } else {
                Response::error('Failed to move uploaded file', 500);
            }
        } elseif (!empty($body['image_base64'])) {
            $raw = $body['image_base64'];
            $ext = 'png';
            if (preg_match('/^data:image\/(\w+);base64,/', $raw, $type)) {
                $raw = substr($raw, strpos($raw, ',') + 1);
                $ext = strtolower($type[1]);
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $ext = 'png';
                }
            }

            $decoded = base64_decode($raw);
            if ($decoded === false) {
                Response::error('Invalid base64 image data', 400);
            }

            $uploadDir = dirname(__DIR__, 5) . '/public/uploads/products';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $newFileName = 'prod_' . $productId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . '/' . $newFileName;

            if (file_put_contents($destination, $decoded) !== false) {
                $filePath = 'uploads/products/' . $newFileName;
                $img = $this->products->addImage($productId, $filePath, $newFileName);
                Response::created($img, 'Image uploaded successfully');
            } else {
                Response::error('Failed to save image file', 500);
            }
        } else {
            Response::error('No image provided. Upload via multipart (image) or base64 (image_base64)', 400);
        }
    }

    /**
     * DELETE /api/v1/products/images/{id} (ERP)
     */
    public function destroyImage(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->products->deleteImage($id);
        Response::json(null, 'Product image removed successfully');
    }
}
