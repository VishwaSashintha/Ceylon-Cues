<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * HTTP Response helper.
 *
 * All responses follow a consistent envelope:
 *   {
 *     "success": bool,
 *     "message": string,
 *     "data": mixed|null,
 *     "errors": object|null,
 *     "meta": { "timestamp": "ISO8601", "version": "1.0" }
 *   }
 */
final class Response
{
    private static function meta(): array
    {
        return [
            'timestamp' => date('c'),
            'version'   => '1.0',
        ];
    }

    /**
     * Send a JSON success response.
     *
     * @param mixed  $data       Response payload
     * @param string $message    Human-readable message
     * @param int    $statusCode HTTP status code
     */
    public static function json(mixed $data = null, string $message = 'Success', int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $response = [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
            'meta'    => self::meta(),
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a JSON error response.
     *
     * @param string $message    Error message
     * @param int    $statusCode HTTP status code
     * @param array  $errors     Validation errors or additional details
     */
    public static function error(string $message = 'Error', int $statusCode = 400, array $errors = []): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $response = [
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => !empty($errors) ? $errors : null,
            'meta'    => self::meta(),
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a paginated JSON response.
     *
     * @param array  $items   Items for the current page
     * @param int    $total   Total number of items
     * @param int    $page    Current page number
     * @param int    $perPage Items per page
     * @param string $message Human-readable message
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'Success'
    ): never {
        $totalPages = (int) ceil($total / max($perPage, 1));

        self::json([
            'items'      => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total_items'  => $total,
                'total_pages'  => $totalPages,
                'has_next'     => $page < $totalPages,
                'has_prev'     => $page > 1,
            ],
        ], $message);
    }

    /**
     * Send a 201 Created response.
     */
    public static function created(mixed $data = null, string $message = 'Created successfully'): never
    {
        self::json($data, $message, 201);
    }

    /**
     * Send a 204 No Content response.
     */
    public static function noContent(): never
    {
        http_response_code(204);
        header('Content-Type: application/json; charset=utf-8');
        exit;
    }

    /**
     * Send a 401 Unauthorized response.
     */
    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    /**
     * Send a 403 Forbidden response.
     */
    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    /**
     * Send a 404 Not Found response.
     */
    public static function notFound(string $message = 'Not found'): never
    {
        self::error($message, 404);
    }

    /**
     * Send a 429 Too Many Requests response.
     */
    public static function tooManyRequests(string $message = 'Too many requests', int $retryAfter = 60): never
    {
        header("Retry-After: {$retryAfter}");
        self::error($message, 429);
    }

    /**
     * Send a 422 Unprocessable Entity (validation error) response.
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): never
    {
        self::error($message, 422, $errors);
    }

    /**
     * Send a 500 Internal Server Error response.
     */
    public static function serverError(string $message = 'Internal server error'): never
    {
        self::error($message, 500);
    }
}
