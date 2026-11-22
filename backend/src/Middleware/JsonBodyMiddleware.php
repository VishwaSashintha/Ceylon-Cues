<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use Closure;

/**
 * Ensures POST, PUT, PATCH with body are valid JSON.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): void
    {
        $method = $request->getMethod();
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $request->getHeader('content-type') ?? '';
            // If body was sent as application/json but could not be parsed
            if (str_contains($contentType, 'application/json')) {
                $rawInput = file_get_contents('php://input');
                if (!empty($rawInput) && json_decode($rawInput, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    Response::error('Invalid JSON payload provided', 400);
                }
            }
        }

        $next();
    }
}
