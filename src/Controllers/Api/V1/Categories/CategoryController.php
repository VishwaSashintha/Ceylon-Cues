<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Categories;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Products\CategoryService;
use Exception;

/**
 * Category REST API Controller
 */
final class CategoryController
{
    private CategoryService $categories;

    public function __construct()
    {
        $this->categories = new CategoryService();
    }

    /**
     * GET /api/v1/categories
     */
    public function index(Request $request): void
    {
        $tree = filter_var($request->getParam('tree', false), FILTER_VALIDATE_BOOLEAN);
        $list = $this->categories->listCategories($tree);
        Response::json($list, 'Categories retrieved');
    }

    /**
     * GET /api/v1/categories/{id}
     */
    public function show(Request $request): void
    {
        $id = $request->getRouteParam('id');
        $cat = $this->categories->getCategory($id);
        if (!$cat) {
            Response::notFound('Category not found');
        }
        Response::json($cat, 'Category retrieved');
    }

    /**
     * POST /api/v1/categories
     */
    public function store(Request $request): void
    {
        $data = $request->getBody();
        $v = Validator::validate($data, ['name' => 'required|string|max:100']);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $cat = $this->categories->createCategory($data);
            Response::created($cat, 'Category created successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/v1/categories/{id}
     */
    public function update(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $data = $request->getBody();
        try {
            $cat = $this->categories->updateCategory($id, $data);
            Response::json($cat, 'Category updated successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/v1/categories/{id}
     */
    public function destroy(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->categories->deleteCategory($id);
        Response::json(null, 'Category deleted successfully');
    }
}
