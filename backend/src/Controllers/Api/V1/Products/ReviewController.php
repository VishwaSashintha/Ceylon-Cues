<?php

declare(strict_types=1);

namespace CeylonCues\Controllers\Api\V1\Products;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Core\Validator;
use CeylonCues\Services\Products\ReviewService;
use Exception;

/**
 * Product Reviews & Moderation Controller
 */
final class ReviewController
{
    private ReviewService $reviews;

    public function __construct()
    {
        $this->reviews = new ReviewService();
    }

    /** GET /api/v1/products/{id}/reviews (Public approved reviews) */
    public function listByProduct(Request $request): void
    {
        $productId = (int)$request->getRouteParam('id');
        $page = (int)($request->getParam('page') ?? 1);
        $perPage = (int)($request->getParam('per_page') ?? 10);

        $result = $this->reviews->getApprovedReviews($productId, $page, $perPage);
        Response::json($result, 'Product reviews retrieved');
    }

    /** POST /api/v1/products/{id}/reviews (Customer submit review) */
    public function submit(Request $request): void
    {
        $productId = (int)$request->getRouteParam('id');
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $data = $request->getBody();
        $v = Validator::validate($data, [
            'rating' => 'required|integer',
        ]);
        if (!$v['valid']) {
            Response::validationError($v['errors']);
        }

        try {
            $result = $this->reviews->submitReview(
                $productId,
                (int)$user['id'],
                (int)$data['rating'],
                $data['review_text'] ?? null
            );
            Response::created($result, $result['message']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /** GET /api/v1/erp/reviews (ERP moderation queue) */
    public function moderationList(Request $request): void
    {
        $status = $request->getParam('status') ?? 'all';
        $page = (int)($request->getParam('page') ?? 1);
        $perPage = (int)($request->getParam('per_page') ?? 20);

        $result = $this->reviews->listReviewsForModeration($status, $page, $perPage);
        Response::json($result, 'Reviews moderation list retrieved');
    }

    /** PATCH /api/v1/erp/reviews/{id}/approve (ERP approve) */
    public function approve(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        try {
            $result = $this->reviews->moderateReview($id, true);
            Response::json($result, 'Review approved successfully');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /** PATCH /api/v1/erp/reviews/{id}/reject (ERP reject) */
    public function reject(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        try {
            $result = $this->reviews->moderateReview($id, false);
            Response::json($result, 'Review rejected');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /** DELETE /api/v1/erp/reviews/{id} (ERP delete) */
    public function destroy(Request $request): void
    {
        $id = (int)$request->getRouteParam('id');
        $this->reviews->deleteReview($id);
        Response::json(['id' => $id], 'Review deleted');
    }
}
