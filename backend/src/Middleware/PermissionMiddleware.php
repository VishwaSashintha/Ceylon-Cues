<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use CeylonCues\Core\Response;
use CeylonCues\Services\Auth\RbacService;
use Closure;

/**
 * Permission Verification Middleware.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    private string $module;
    private string $action;
    private RbacService $rbac;

    public function __construct(string $module, string $action = 'view')
    {
        $this->module = $module;
        $this->action = $action;
        $this->rbac = new RbacService();
    }

    public function handle(Request $request, Closure $next): void
    {
        $user = $request->getUser();

        if (!$user) {
            Response::unauthorized('Authentication required');
        }

        if (!$this->rbac->hasPermission($user, $this->module, $this->action)) {
            Response::forbidden("You do not have permission to {$this->action} in module '{$this->module}'");
        }

        $next();
    }
}
