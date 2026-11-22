<?php

declare(strict_types=1);

namespace CeylonCues\Core;

use RuntimeException;

/**
 * Application Logger
 *
 * Writes structured log entries to daily rotating log files
 * in the storage/logs/ directory.
 *
 * Log format: [YYYY-MM-DD HH:MM:SS] LEVEL: Message {context_json}
 *
 * @package CeylonCues\Core
 */
final class Logger
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var string Absolute path to the logs directory */
    private string $logDir;

    /** Log level constants (RFC 5424) */
    public const LEVEL_DEBUG    = 'DEBUG';
    public const LEVEL_INFO     = 'INFO';
    public const LEVEL_WARNING  = 'WARNING';
    public const LEVEL_ERROR    = 'ERROR';
    public const LEVEL_CRITICAL = 'CRITICAL';

    /** @var array<string, int> Log level priority (lower = more verbose) */
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG    => 0,
        self::LEVEL_INFO     => 1,
        self::LEVEL_WARNING  => 2,
        self::LEVEL_ERROR    => 3,
        self::LEVEL_CRITICAL => 4,
    ];

    /** @var int Minimum log level priority to actually write */
    private int $minPriority;

    /**
     * Private constructor for singleton pattern.
     *
     * @param string $logDir   Absolute path to the logs directory
     * @param string $minLevel Minimum log level to record
     */
    private function __construct(string $logDir, string $minLevel = self::LEVEL_DEBUG)
    {
        $this->logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        $this->minPriority = self::LEVEL_PRIORITY[strtoupper($minLevel)] ?? 0;

        if (!is_dir($this->logDir)) {
            if (!mkdir($this->logDir, 0755, true) && !is_dir($this->logDir)) {
                throw new RuntimeException("Failed to create log directory: {$this->logDir}");
            }
        }
    }

    /** Prevent cloning of singleton */
    private function __clone(): void {}

    /**
     * Get the singleton Logger instance.
     *
     * @param string|null $logDir   Path to logs directory (only used on first call)
     * @param string|null $minLevel Minimum log level (only used on first call)
     * @return self
     */
    public static function getInstance(?string $logDir = null, ?string $minLevel = null): self
    {
        if (self::$instance === null) {
            $dir = $logDir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            $level = $minLevel ?? self::LEVEL_DEBUG;
            self::$instance = new self($dir, $level);
        }

        return self::$instance;
    }

    /**
     * Log a debug message.
     *
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an informational message.
     *
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log a critical message.
     *
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    public static function logInfo(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function logWarning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function logError(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function logCritical(string $message, array $context = []): void
    {
        self::getInstance()->critical($message, $context);
    }

    public static function logDebug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }

    /**
     * Write a log entry to the daily log file.
     *
     * @param string               $level   Log level
     * @param string               $message Log message
     * @param array<string, mixed> $context Contextual data
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $priority = self::LEVEL_PRIORITY[$level] ?? 0;

        if ($priority < $this->minPriority) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context)
            ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $entry = "[{$timestamp}] {$level}: {$message}{$contextStr}" . PHP_EOL;

        $filename = date('Y-m-d') . '.log';
        $filepath = $this->logDir . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($filepath, $entry, FILE_APPEND | LOCK_EX);
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
