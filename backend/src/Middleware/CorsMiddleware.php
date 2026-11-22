<?php

declare(strict_types=1);

namespace CeylonCues\Middleware;

use CeylonCues\Core\Request;
use Closure;

final class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;

    public function __construct()
    {
        $originsEnv = $_ENV['ALLOWED_ORIGINS'] ?? '';
        if (!empty($originsEnv)) {
            $this->allowedOrigins = array_map('trim', explode(',', $originsEnv));
        } else {
            $this->allowedOrigins = [
                'http://localhost:3000',
                'http://localhost:5173',
                'http://localhost:8080',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:8080',
                'https://ceyloncues.lk',
                'https://admin.ceyloncues.lk',
                'https://pos.ceyloncues.lk',
            ];
        }
    }

    public function handle(Request $request, Closure $next): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (!empty($origin) && in_array($origin, $this->allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-Token, Idempotency-Key');
        header('Access-Control-Max-Age: 86400');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $next();
    }
}
