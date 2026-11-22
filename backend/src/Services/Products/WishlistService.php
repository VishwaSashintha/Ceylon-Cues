<?php

declare(strict_types=1);

namespace CeylonCues\Services\Products;

use CeylonCues\Core\Database;
use PDO;

final class WishlistService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function addToWishlist(int $customerId, int $productId): void
    {
        $this->db->prepare("
            INSERT IGNORE INTO wishlists (user_id, product_id, created_at)
            VALUES (:uid, :pid, NOW())
        ")->execute([':uid' => $customerId, ':pid' => $productId]);
    }

    public function removeFromWishlist(int $customerId, int $productId): void
    {
        $this->db->prepare("
            DELETE FROM wishlists WHERE user_id = :uid AND product_id = :pid
        ")->execute([':uid' => $customerId, ':pid' => $productId]);
    }

    public function getWishlist(int $customerId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*
            FROM wishlists w
            JOIN products p ON p.id = w.product_id
            WHERE w.user_id = :uid
              AND p.is_active = 1
              AND p.deleted_at IS NULL
            ORDER BY w.created_at DESC
        ");
        $stmt->execute([':uid' => $customerId]);
        return $stmt->fetchAll();
    }

    public function requestStockNotification(int $customerId, int $productId): void
    {
        $this->db->prepare("
            INSERT IGNORE INTO stock_notification_requests (user_id, product_id, created_at)
            VALUES (:uid, :pid, NOW())
        ")->execute([':uid' => $customerId, ':pid' => $productId]);
    }
}
