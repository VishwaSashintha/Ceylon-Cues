<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use Closure;

/**
 * Role Verification Middleware.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    private array $allowedRoles;

    public function __construct(array|string $roles)
    {
        $this->allowedRoles = is_array($roles) ? $roles : [$roles];
    }

    public function handle(Request $request, Closure $next): void
    {
        $user = $request->getUser();

        if (!$user) {
            Response::unauthorized('Authentication required');
        }

        // Owner always bypasses role checks
        if (!empty($user['is_owner']) || ($user['role'] ?? '') === 'owner') {
            $next();
            return;
        }

        if (!in_array($user['role'] ?? '', $this->allowedRoles, true)) {
            Response::forbidden('Access denied for your role: ' . ($user['role'] ?? 'guest'));
        }

        $next();
    }
}
