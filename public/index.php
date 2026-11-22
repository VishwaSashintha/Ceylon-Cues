<?php

declare(strict_types=1);

/**
 * Ceylon Cues — Front Controller
 *
 * All API requests route through this single entry point.
 */

// Autoload composer packages / PSR-4 classes
$basePath = dirname(__DIR__);
$autoloader = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    // Built-in fallback PSR-4 autoloader
    spl_autoload_register(function (string $class) use ($basePath) {
        $prefix = 'CeylonCues\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use CeylonCues\Core\Application;

$app = new Application($basePath);
$app->boot();

// Load routes
$routesFile = $basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
if (file_exists($routesFile)) {
    $router = $app->getRouter();
    require_once $routesFile;
}

$app->run();
