<?php

declare(strict_types=1);

namespace CeylonCues\Core;

use RuntimeException;

/**
 * Simple PHP Router supporting grouped routes, middleware, and route parameters.
 *
 * Supports GET, POST, PUT, PATCH, DELETE methods.
 * Route parameters use {param} syntax.
 */
final class Router
{
    /** @var array<string, array<array{pattern: string, handler: array, middleware: array}>> */
    private array $routes = [];

    /** @var string Current group prefix */
    private string $groupPrefix = '';

    /** @var array Current group middleware stack */
    private array $groupMiddleware = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, array $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, array $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, array $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, array $handler, array $middleware = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, array $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Group routes under a common prefix and optional middleware.
     *
     * @param string   $prefix     URL prefix for the group
     * @param callable $callback   Receives the Router instance
     * @param array    $middleware Middleware to apply to all routes in group
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Add a route to the registry.
     */
    private function addRoute(string $method, string $path, array $handler, array $middleware = []): self
    {
        $fullPath = $this->groupPrefix . $path;
        $pattern = $this->pathToRegex($fullPath);
        $allMiddleware = array_merge($this->groupMiddleware, $middleware);

        $this->routes[$method][] = [
            'pattern'    => $pattern,
            'path'       => $fullPath,
            'handler'    => $handler,
            'middleware'  => $allMiddleware,
        ];

        return $this;
    }

    /**
     * Convert a route path with {params} to a regex pattern.
     */
    private function pathToRegex(string $path): string
    {
        $path = rtrim($path, '/') ?: '/';
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '/?$#';
    }

    /**
     * Dispatch the request to the matching route.
     */
    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Handle OPTIONS for CORS preflight
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                $request->setRouteParams($params);

                // Run middleware chain
                $this->runMiddleware($route['middleware'], $request, function () use ($route, $request) {
                    $this->callHandler($route['handler'], $request);
                });
                return;
            }
        }

        // No route matched
        Response::notFound('Route not found');
    }

    /**
     * Run the middleware chain.
     *
     * @param array    $middleware List of middleware class names or instances
     * @param Request  $request   The current request
     * @param callable $next      The final handler to call if all middleware passes
     */
    private function runMiddleware(array $middleware, Request $request, callable $next): void
    {
        if (empty($middleware)) {
            $next();
            return;
        }

        $current = array_shift($middleware);
        $remaining = $middleware;

        if (is_string($current)) {
            $instance = new $current();
        } elseif (is_array($current) && count($current) === 2) {
            // [ClassName::class, ['param1', 'param2']]
            $class = $current[0];
            $params = $current[1];
            $instance = new $class(...(is_array($params) ? $params : [$params]));
        } else {
            $instance = $current;
        }

        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException('Middleware must implement handle() method: ' . get_class($instance));
        }

        $instance->handle($request, function () use ($remaining, $request, $next) {
            $this->runMiddleware($remaining, $request, $next);
        });
    }

    /**
     * Call the route handler (controller method).
     *
     * @param array   $handler [ControllerClass, 'method']
     * @param Request $request
     */
    private function callHandler(array $handler, Request $request): void
    {
        if (count($handler) !== 2) {
            throw new RuntimeException('Route handler must be [Controller::class, "method"]');
        }

        [$controllerClass, $method] = $handler;

        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller class not found: {$controllerClass}");
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Method {$method} not found in {$controllerClass}");
        }

        $controller->{$method}($request);
    }

    /**
     * Get all registered routes (for debugging/documentation).
     *
     * @return array<string, array>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
