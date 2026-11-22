<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use Closure;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'");
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        $next();
    }
}
