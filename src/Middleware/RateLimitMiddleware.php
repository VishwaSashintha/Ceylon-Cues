<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Database;
use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use Closure;
use PDO;

/**
 * Rate Limiting Middleware.
 *
 * Enforces per-IP request frequency rules.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private PDO $db;
    private int $maxAttempts;
    private int $windowSeconds;
    private string $action;

    public function __construct(int $maxAttempts = 60, int $windowSeconds = 60, string $action = 'api')
    {
        $this->db = Database::getInstance()->getConnection();
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->action = $action;
    }

    public function handle(Request $request, Closure $next): void
    {
        $ip = $request->getIp() ?? '127.0.0.1';
        $identifier = $ip;

        // Cleanup expired records
        $this->db->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()")->execute();

        // Check attempts
        $stmt = $this->db->prepare("SELECT attempts, expires_at FROM rate_limits WHERE identifier = :id AND action = :action");
        $stmt->execute([':id' => $identifier, ':action' => $this->action]);
        $record = $stmt->fetch();

        if ($record) {
            if ($record['attempts'] >= $this->maxAttempts) {
                $retryAfter = max(1, strtotime($record['expires_at']) - time());
                Response::tooManyRequests("Rate limit exceeded for action '{$this->action}'. Please try again later.", $retryAfter);
            }

            // Increment attempt
            $this->db->prepare("UPDATE rate_limits SET attempts = attempts + 1 WHERE identifier = :id AND action = :action")
                ->execute([':id' => $identifier, ':action' => $this->action]);
        } else {
            $expiresAt = date('Y-m-d H:i:s', time() + $this->windowSeconds);
            $this->db->prepare("INSERT INTO rate_limits (identifier, action, attempts, expires_at) VALUES (:id, :action, 1, :expires_at)")
                ->execute([':id' => $identifier, ':action' => $this->action, ':expires_at' => $expiresAt]);
        }

        $next();
    }
}
