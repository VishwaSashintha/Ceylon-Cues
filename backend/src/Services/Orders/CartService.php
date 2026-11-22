<?php

declare(strict_types=1);

namespace CeylonCues\Services\Orders;

use CeylonCues\Core\Database;
use CeylonCues\Core\Uuid;
use PDO;
use RuntimeException;

/**
 * Customer Shopping Cart Service
 *
 * Supports registered customer accounts as well as guest sessions.
 */
final class CartService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get or create active shopping cart for user or guest session.
     */
    public function getOrCreateCart(?int $userId = null, ?string $sessionId = null): array
    {
        if (!$userId && !$sessionId) {
            $sessionId = bin2hex(random_bytes(16));
        }

        $cart = null;
        if ($userId) {
            $stmt = $this->db->prepare("SELECT * FROM carts WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $cart = $stmt->fetch();
        } elseif ($sessionId) {
            $stmt = $this->db->prepare("SELECT * FROM carts WHERE session_id = :sid LIMIT 1");
            $stmt->execute([':sid' => $sessionId]);
            $cart = $stmt->fetch();
        }

        if (!$cart) {
            $uuid = Uuid::v4();
            $stmt = $this->db->prepare("
                INSERT INTO carts (uuid, user_id, session_id, expires_at)
                VALUES (:uuid, :uid, :sid, DATE_ADD(NOW(), INTERVAL 30 DAY))
            ");
            $stmt->execute([':uuid' => $uuid, ':uid' => $userId, ':sid' => $sessionId]);
            $cartId = (int)$this->db->lastInsertId();
            $cart = ['id' => $cartId, 'uuid' => $uuid, 'user_id' => $userId, 'session_id' => $sessionId];
        }

        return $this->getCartContents((int)$cart['id']);
    }

    /**
     * Add item to cart.
     */
    public function addItem(int $cartId, int $productId, int $quantity = 1, ?int $variantId = null): array
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero');
        }

        // Verify product exists, active and has online stock
        $stmtProd = $this->db->prepare("
            SELECT p.id, p.name, p.retail_price, p.is_active, p.is_published,
                   COALESCE(osa.allocated_quantity, 0) as online_stock,
                   COALESCE(osa.allow_backorder, 0) as allow_backorder
            FROM products p
            LEFT JOIN online_stock_allocations osa ON p.id = osa.product_id
            WHERE p.id = :id AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmtProd->execute([':id' => $productId]);
        $prod = $stmtProd->fetch();

        if (!$prod || !$prod['is_active'] || !$prod['is_published']) {
            throw new RuntimeException('Product is not available for purchase');
        }

        // Check if item already exists in cart
        $stmtItem = $this->db->prepare("
            SELECT id, quantity FROM cart_items
            WHERE cart_id = :cid AND product_id = :pid AND (variant_id = :vid OR (variant_id IS NULL AND :vid2 IS NULL))
        ");
        $stmtItem->execute([':cid' => $cartId, ':pid' => $productId, ':vid' => $variantId, ':vid2' => $variantId]);
        $existing = $stmtItem->fetch();

        $newQty = $existing ? ((int)$existing['quantity'] + $quantity) : $quantity;

        // Verify stock allocation limit
        if ($newQty > (int)$prod['online_stock'] && empty($prod['allow_backorder'])) {
            throw new RuntimeException("Cannot add {$quantity} more units. Only {$prod['online_stock']} units available online.");
        }

        if ($existing) {
            $this->db->prepare("UPDATE cart_items SET quantity = :qty, updated_at = NOW() WHERE id = :id")
                ->execute([':qty' => $newQty, ':id' => $existing['id']]);
        } else {
            $this->db->prepare("
                INSERT INTO cart_items (cart_id, product_id, variant_id, quantity)
                VALUES (:cid, :pid, :vid, :qty)
            ")->execute([':cid' => $cartId, ':pid' => $productId, ':vid' => $variantId, ':qty' => $newQty]);
        }

        return $this->getCartContents($cartId);
    }

    /**
     * Update item quantity in cart.
     */
    public function updateItemQuantity(int $cartId, int $itemId, int $quantity): array
    {
        if ($quantity <= 0) {
            $this->removeItem($cartId, $itemId);
        } else {
            $this->db->prepare("UPDATE cart_items SET quantity = :qty, updated_at = NOW() WHERE id = :id AND cart_id = :cid")
                ->execute([':qty' => $quantity, ':id' => $itemId, ':cid' => $cartId]);
        }

        return $this->getCartContents($cartId);
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(int $cartId, int $itemId): array
    {
        $this->db->prepare("DELETE FROM cart_items WHERE id = :id AND cart_id = :cid")
            ->execute([':id' => $itemId, ':cid' => $cartId]);
        return $this->getCartContents($cartId);
    }

    /**
     * Clear all items in cart.
     */
    public function clearCart(int $cartId): void
    {
        $this->db->prepare("DELETE FROM cart_items WHERE cart_id = :cid")->execute([':cid' => $cartId]);
    }

    /**
     * Get detailed cart contents with calculated subtotals.
     */
    public function getCartContents(int $cartId): array
    {
        $stmtCart = $this->db->prepare("SELECT * FROM carts WHERE id = :id LIMIT 1");
        $stmtCart->execute([':id' => $cartId]);
        $cart = $stmtCart->fetch();

        if (!$cart) {
            throw new RuntimeException('Cart not found');
        }

        $stmtItems = $this->db->prepare("
            SELECT ci.id as item_id, ci.quantity, ci.variant_id,
                   p.id as product_id, p.name as product_name, p.sku, p.retail_price, p.weight_kg,
                   (ci.quantity * p.retail_price) as total_price,
                   (SELECT file_path FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_image
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.cart_id = :cid
            ORDER BY ci.created_at ASC
        ");
        $stmtItems->execute([':cid' => $cartId]);
        $items = $stmtItems->fetchAll();

        $subtotal = 0.00;
        $totalWeight = 0.00;
        $totalItems = 0;

        foreach ($items as &$item) {
            $item['unit_price'] = (float)$item['retail_price'];
            $item['total_price'] = (float)$item['total_price'];
            $subtotal += $item['total_price'];
            $totalWeight += ((float)($item['weight_kg'] ?? 0.5)) * $item['quantity'];
            $totalItems += (int)$item['quantity'];
        }

        return [
            'cart_id'      => $cartId,
            'uuid'         => $cart['uuid'],
            'user_id'      => $cart['user_id'],
            'session_id'   => $cart['session_id'],
            'items'        => $items,
            'item_count'   => $totalItems,
            'subtotal'     => $subtotal,
            'total_weight' => round($totalWeight, 3),
            'currency'     => 'LKR',
        ];
    }
}
