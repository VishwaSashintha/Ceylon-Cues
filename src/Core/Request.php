<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * HTTP Request wrapper.
 *
 * Encapsulates superglobals and provides clean access to request data,
 * headers, and authenticated user context.
 */
final class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $body;
    private array $headers;
    private array $server;
    private array $files;
    private array $cookies;
    private array $routeParams = [];
    private ?array $user = null;
    private array $attributes = [];

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $rawPath       = parse_url($this->uri, PHP_URL_PATH) ?: '/';
        $rawPath       = urldecode($rawPath);
        $apiPos        = strpos($rawPath, '/api/v1');
        if ($apiPos !== false && $apiPos > 0) {
            $this->path = substr($rawPath, $apiPos);
        } else {
            $this->path = $rawPath;
        }
        $this->query   = $_GET;
        $this->server  = $_SERVER;
        $this->files   = $_FILES;
        $this->cookies = $_COOKIE;
        $this->headers = $this->parseHeaders();
        $this->body    = $this->parseBody();
    }

    /**
     * Parse request headers from $_SERVER.
     */
    private function parseHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $k => $v) {
                    $headers[strtolower((string)$k)] = (string)$v;
                }
            }
        }

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($this->server['REDIRECT_HTTP_AUTHORIZATION']) && empty($headers['authorization'])) {
            $headers['authorization'] = $this->server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->server['CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Parse request body for JSON and form data.
     */
    private function parseBody(): array
    {
        $contentType = $this->server['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            if ($rawBody !== false && $rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                return is_array($decoded) ? $decoded : [];
            }
            return [];
        }

        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (!empty($_POST)) {
                return $_POST;
            }
            $rawBody = file_get_contents('php://input');
            if ($rawBody !== false && $rawBody !== '') {
                parse_str($rawBody, $parsed);
                return $parsed;
            }
        }

        return [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return rtrim($this->path, '/') ?: '/';
    }

    /**
     * Get a query parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters.
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Get a body parameter.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all body parameters.
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Get only specified keys from body.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->body, array_flip($keys));
    }

    /**
     * Get a request header (lowercase key).
     */
    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * Get all headers.
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    /**
     * Get the client IP address.
     */
    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_X_REAL_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    /**
     * Get the user agent string.
     */
    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Set route parameters (called by Router).
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get a route parameter.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Get all route parameters.
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Set the authenticated user data.
     */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the authenticated user data.
     */
    public function user(): ?array
    {
        return $this->user;
    }

    /**
     * Check if request is from an authenticated user.
     */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set a custom attribute on the request.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a custom attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if the request expects JSON.
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json') || str_contains($accept, '*/*');
    }

    /**
     * Check if the request is AJAX / XHR.
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get pagination page number from query.
     */
    public function page(int $default = 1): int
    {
        $page = (int) $this->query('page', $default);
        return max(1, $page);
    }

    /**
     * Get per-page limit from query.
     */
    public function perPage(int $default = 20, int $max = 100): int
    {
        $perPage = (int) $this->query('per_page', $default);
        return min(max(1, $perPage), $max);
    }

    /**
     * Aliases for controller compatibility
     */
    public function getBody(): array
    {
        return $this->body;
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? ($this->body[$key] ?? $default);
    }

    public function getRouteParam(string $key, mixed $default = null): mixed
    {
        return $this->param($key, $default);
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getHeader(string $key, ?string $default = null): ?string
    {
        return $this->header($key, $default);
    }

    public function getBearerToken(): ?string
    {
        return $this->bearerToken();
    }
}
