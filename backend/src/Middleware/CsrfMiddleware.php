<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Database;
use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use Closure;
use PDO;

/**
 * Cross-Site Request Forgery (CSRF) Protection Middleware.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function handle(Request $request, Closure $next): void
    {
        // Safe HTTP methods do not require CSRF
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $next();
            return;
        }

        $token = $request->getHeader('x-csrf-token') ?? $request->getParam('_csrf_token');

        if (empty($token)) {
            Response::error('CSRF token is missing', 403);
        }

        // Validate token
        $stmt = $this->db->prepare("SELECT id FROM csrf_tokens WHERE token = :token AND expires_at > NOW() LIMIT 1");
        $stmt->execute([':token' => $token]);
        $valid = $stmt->fetch();

        if (!$valid) {
            Response::error('Invalid or expired CSRF token', 403);
        }

        $next();
    }

    /**
     * Generate a new CSRF token.
     */
    public static function generateToken(?int $userId = null, ?string $sessionId = null): string
    {
        $db = Database::getInstance()->getConnection();
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 7200); // 2 hours

        $stmt = $db->prepare("INSERT INTO csrf_tokens (token, session_id, user_id, expires_at) VALUES (:token, :session_id, :user_id, :expires_at)");
        $stmt->execute([
            ':token' => $token,
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':expires_at' => $expiresAt,
        ]);

        return $token;
    }
}
