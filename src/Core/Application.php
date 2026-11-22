<?php

declare(strict_types=1);

namespace CeylonCues\Core;

use Throwable;

/**
 * Main application bootstrap & lifecycle manager.
 */
final class Application
{
    private static ?self $instance = null;
    private string $basePath;
    private Router $router;
    private Database $database;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        self::$instance = $this;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function boot(): self
    {
        // 1. Load environment variables
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        // 2. Set timezone
        $timezone = Env::get('APP_TIMEZONE', 'Asia/Colombo');
        date_default_timezone_set($timezone);

        // 3. Error handling
        $debug = filter_var(Env::get('APP_DEBUG', true), FILTER_VALIDATE_BOOLEAN);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_exception_handler(function (Throwable $e) use ($debug) {
            Logger::logCritical('Uncaught exception: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($debug) {
                Response::error($e->getMessage(), 500, [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ]);
            } else {
                Response::error('An unexpected server error occurred.', 500);
            }
        });

        // 4. Initialize Core Singletons
        Config::init($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $this->database = Database::getInstance();
        $this->router = new Router();

        return $this;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function run(): void
    {
        $request = new Request();
        $this->router->dispatch($request);
    }
}
