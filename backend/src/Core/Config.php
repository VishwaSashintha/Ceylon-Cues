<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * Configuration Manager
 *
 * Loads PHP config files from the config/ directory and provides
 * dot-notation access to nested configuration values.
 *
 * Usage:
 *   $config = Config::getInstance();
 *   $host = $config->get('database.host');
 *   $appName = $config->get('app.app_name', 'Default');
 *
 * @package CeylonCues\Core
 */
final class Config
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var array<string, mixed> Loaded configuration values keyed by filename */
    private array $config = [];

    /** @var string Absolute path to the config directory */
    private string $configPath;

    /**
     * Private constructor to enforce singleton pattern.
     *
     * @param string $configPath Path to the configuration directory
     */
    private function __construct(string $configPath)
    {
        $this->configPath = rtrim($configPath, DIRECTORY_SEPARATOR);
        $this->loadAll();
    }

    /** Prevent cloning of singleton */
    private function __clone(): void {}

    /**
     * Get the singleton Config instance.
     *
     * @param string|null $configPath Path to config directory (only used on first call)
     * @return self
     */
    public static function getInstance(?string $configPath = null): self
    {
        if (self::$instance === null) {
            $path = $configPath ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config';
            self::$instance = new self($path);
        }

        return self::$instance;
    }

    public static function init(?string $configPath = null): self
    {
        return self::getInstance($configPath);
    }

    public static function getStatic(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->get($key, $default);
    }

    /**
     * Load all PHP config files from the config directory.
     *
     * Each file should return an associative array. The filename (without
     * extension) becomes the top-level key.
     *
     * @return void
     */
    private function loadAll(): void
    {
        $pattern = $this->configPath . DIRECTORY_SEPARATOR . '*.php';
        $files = glob($pattern);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $key = basename($file, '.php');
            /** @var array<string, mixed> $values */
            $values = require $file;

            if (is_array($values)) {
                $this->config[$key] = $values;
            }
        }
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param string $key     Dot-notated key (e.g., 'database.host')
     * @param mixed  $default Default value if key is not found
     * @return mixed
     *
     * @example
     *   Config::getInstance()->get('database.host');       // '127.0.0.1'
     *   Config::getInstance()->get('app.debug', false);    // true
     *   Config::getInstance()->get('app');                 // entire app config array
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a configuration value at runtime (not persisted).
     *
     * @param string $key   Dot-notated key
     * @param mixed  $value Value to set
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $config = &$this->config;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $config[$segment] = $value;
            } else {
                if (!isset($config[$segment]) || !is_array($config[$segment])) {
                    $config[$segment] = [];
                }
                $config = &$config[$segment];
            }
        }
    }

    /**
     * Check if a configuration key exists.
     *
     * @param string $key Dot-notated key
     * @return bool
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Get all loaded configuration values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Reset the singleton instance (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
