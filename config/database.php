<?php

declare(strict_types=1);

/**
 * Database Configuration
 *
 * MySQL/MariaDB connection settings loaded from environment variables.
 * All financial columns in the schema MUST use DECIMAL types.
 *
 * @return array<string, mixed>
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Database Host
    |--------------------------------------------------------------------------
    */
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',

    /*
    |--------------------------------------------------------------------------
    | Database Port
    |--------------------------------------------------------------------------
    */
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),

    /*
    |--------------------------------------------------------------------------
    | Database Name
    |--------------------------------------------------------------------------
    */
    'database' => $_ENV['DB_DATABASE'] ?? 'ceylon_cues',

    /*
    |--------------------------------------------------------------------------
    | Database Username
    |--------------------------------------------------------------------------
    */
    'username' => $_ENV['DB_USERNAME'] ?? 'root',

    /*
    |--------------------------------------------------------------------------
    | Database Password
    |--------------------------------------------------------------------------
    */
    'password' => $_ENV['DB_PASSWORD'] ?? '',

    /*
    |--------------------------------------------------------------------------
    | Character Set & Collation
    |--------------------------------------------------------------------------
    | utf8mb4 supports full Unicode including emoji and special characters.
    */
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
];
