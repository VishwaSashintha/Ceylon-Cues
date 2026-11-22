<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use Closure;

/**
 * CORS Middleware for REST API endpoints.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Idempotency-Key');
        header('Access-Control-Max-Age: 86400');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $next();
    }
}
