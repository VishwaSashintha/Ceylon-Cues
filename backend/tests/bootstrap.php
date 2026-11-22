<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Colombo');
