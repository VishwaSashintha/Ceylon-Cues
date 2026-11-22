<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * Loads core application settings from environment variables.
 * All values fall back to sensible defaults for development.
 *
 * @return array<string, mixed>
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'app_name' => $_ENV['APP_NAME'] ?? 'Ceylon Cues',

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    | Supported: "local", "staging", "production"
    */
    'env' => $_ENV['APP_ENV'] ?? 'local',

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    | When true, detailed error messages are shown. MUST be false in production.
    */
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Colombo',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    | Used for HMAC signatures, CSRF tokens, and other security operations.
    | Must be set in production via APP_KEY environment variable.
    */
    'key' => $_ENV['APP_KEY'] ?? '',

    /*
    |--------------------------------------------------------------------------
    | Locale Configuration
    |--------------------------------------------------------------------------
    */
    'locale' => $_ENV['APP_LOCALE'] ?? 'en',

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'log_level' => $_ENV['LOG_LEVEL'] ?? 'debug',
];
