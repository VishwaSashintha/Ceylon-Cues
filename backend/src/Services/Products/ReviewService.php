<?php

declare(strict_types=1);

namespace CeylonCues\Services\Products;

use CeylonCues\Core\Database;
use PDO;
use RuntimeException;

/**
 * Product Reviews & Moderation Service
 */
final class ReviewService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Submit a customer review (queued as pending moderation: is_approved = 0).
     */
    public function submitReview(int $productId, int $userId, int $rating, ?string $reviewText = null): array
    {
        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Rating must be between 1 and 5 stars');
        }

        // Verify if user previously purchased the product (verified buyer flag)
        $isVerified = (int)$this->db->query("
            SELECT COUNT(*) 
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = {$userId} 
              AND oi.product_id = {$productId}
              AND o.status IN ('delivered', 'shipped', 'confirmed')
        ")->fetchColumn() > 0 ? 1 : 0;

        $stmt = $this->db->prepare("
            INSERT INTO product_reviews (product_id, user_id, rating, review_text, is_approved, is_verified_purchase)
            VALUES (:pid, :uid, :rating, :text, 0, :ver)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), is_approved = 0, updated_at = NOW()
        ");
        $stmt->execute([
            ':pid'    => $productId,
            ':uid'    => $userId,
            ':rating' => $rating,
            ':text'   => $reviewText ? trim($reviewText) : null,
            ':ver'    => $isVerified,
        ]);

        $id = (int)$this->db->lastInsertId();
        return [
            'id'                   => $id ?: (int)$this->db->query("SELECT id FROM product_reviews WHERE product_id = {$productId} AND user_id = {$userId}")->fetchColumn(),
            'product_id'           => $productId,
            'rating'               => $rating,
            'is_approved'          => false,
            'is_verified_purchase' => (bool)$isVerified,
            'message'              => 'Thank you! Your review has been submitted and is pending moderation.',
        ];
    }

    /**
     * List public approved reviews for a product.
     */
    public function getApprovedReviews(int $productId, int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("
            SELECT COUNT(*), COALESCE(AVG(rating), 0) AS avg_rating 
            FROM product_reviews 
            WHERE product_id = :pid AND is_approved = 1
        ");
        $countStmt->execute([':pid' => $productId]);
        $stats = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($stats['COUNT(*)'] ?? 0);
        $avgRating = round((float)($stats['avg_rating'] ?? 0), 1);

        $stmt = $this->db->prepare("
            SELECT r.id, r.product_id, r.rating, r.review_text, r.is_verified_purchase, r.created_at,
                   CONCAT(u.first_name, ' ', SUBSTRING(u.last_name, 1, 1), '.') AS reviewer_name
            FROM product_reviews r
            JOIN users u ON u.id = r.user_id
            WHERE r.product_id = :pid AND r.is_approved = 1
            ORDER BY r.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'product_id'   => $productId,
            'average_rating' => $avgRating,
            'total_reviews'  => $total,
            'items'          => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * ERP Moderation: List all reviews (with optional status filter: 'pending', 'approved', 'all').
     */
    public function listReviewsForModeration(?string $status = 'all', int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $where = [];
        $params = [];

        if ($status === 'pending') {
            $where[] = 'r.is_approved = 0';
        } elseif ($status === 'approved') {
            $where[] = 'r.is_approved = 1';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = (int)$this->db->query("SELECT COUNT(*) FROM product_reviews r {$whereSql}")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT r.*, p.name AS product_name, p.sku AS product_sku,
                   CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
                   u.email AS customer_email
            FROM product_reviews r
            JOIN products p ON p.id = r.product_id
            JOIN users u ON u.id = r.user_id
            {$whereSql}
            ORDER BY r.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'      => $count,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> (int)ceil($count / max($perPage, 1)),
        ];
    }

    /**
     * ERP Moderation: Approve or reject review.
     */
    public function moderateReview(int $reviewId, bool $approve): array
    {
        $stmt = $this->db->prepare("UPDATE product_reviews SET is_approved = :app, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':app' => $approve ? 1 : 0, ':id' => $reviewId]);

        return [
            'id'          => $reviewId,
            'is_approved' => $approve,
            'status'      => $approve ? 'approved' : 'rejected',
        ];
    }

    /**
     * ERP Moderation: Delete review.
     */
    public function deleteReview(int $reviewId): bool
    {
        return $this->db->prepare("DELETE FROM product_reviews WHERE id = :id")->execute([':id' => $reviewId]);
    }
}
