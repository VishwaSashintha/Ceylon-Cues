<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use Closure;

/**
 * Middleware Interface.
 */
interface MiddlewareInterface
{
    /**
     * Handle incoming request.
     */
    public function handle(Request $request, Closure $next): void;
}
