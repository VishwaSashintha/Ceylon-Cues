<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Auth\AuthService;
use Closure;

/**
 * Authentication Middleware.
 *
 * Validates Bearer tokens and populates $request->setUser().
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function handle(Request $request, Closure $next): void
    {
        $token = $request->getBearerToken();

        if (empty($token)) {
            Response::unauthorized('Authentication token is required');
        }

        $user = $this->authService->validateSession($token);

        if (!$user) {
            Response::unauthorized('Invalid or expired authentication session');
        }

        $request->setUser($user);
        $next();
    }
}
