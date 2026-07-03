<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central logger with configurable verbosity.
 *
 * At production scale (~200k visits/day) the plugin's per-request debug logs
 * would flood the error log and degrade disk I/O. This logger gates each
 * message behind a level so production can keep only real errors while
 * development sees everything.
 *
 * Levels (low to high verbosity):
 *   error (0) — something is broken (Redis down, decryption failed, hit failed).
 *               Always logged. The safe production default.
 *   info  (1) — notable lifecycle events (stats rebuild completed, orphan sweep).
 *   debug (2) — per-request detail (variant assignment, adapter decision,
 *               returning-visitor reads). The noisy ones at high traffic.
 *
 * Configure in wp-config.php, e.g.:
 *   define('ABTF_LOG_LEVEL', 'debug');   // development
 *   define('ABTF_LOG_LEVEL', 'error');   // production (or omit — error is default)
 *
 * Messages are prefixed '[AB Test]' to match the existing log convention.
 */
class Logger
{
    private const LEVELS = [
        'error' => 0,
        'info'  => 1,
        'debug' => 2,
    ];

    private const DEFAULT_LEVEL = 'error';

    private static ?int $threshold = null;

    /**
     * Logs an error — always emitted regardless of configured level.
     */
    public static function error(string $message): void
    {
        self::write('error', $message);
    }

    /**
     * Logs an informational lifecycle event (level >= info).
     */
    public static function info(string $message): void
    {
        self::write('info', $message);
    }

    /**
     * Logs per-request detail (level >= debug). Suppressed in production.
     */
    public static function debug(string $message): void
    {
        self::write('debug', $message);
    }

    /**
     * Whether the configured level includes debug verbosity.
     *
     * Exposed so the frontend (via wp_localize_script) can gate its own
     * console output behind the same ABTF_LOG_LEVEL switch that controls
     * PHP logging, keeping both halves of the plugin on one control.
     */
    public static function isDebug(): bool
    {
        if (self::$threshold === null) {
            self::$threshold = self::resolveThreshold();
        }

        return self::$threshold >= self::LEVELS['debug'];
    }

    /**
     * Emits the message only if its level is at or below the configured
     * threshold. Resolves and caches the threshold once per request.
     */
    private static function write(string $level, string $message): void
    {
        if (self::$threshold === null) {
            self::$threshold = self::resolveThreshold();
        }

        $levelValue = self::LEVELS[$level] ?? 0;

        if ($levelValue <= self::$threshold) {
            error_log('[AB Test] ' . $message);
        }
    }

    /**
     * Reads ABTF_LOG_LEVEL (if defined and valid), else falls back to the
     * conservative production default.
     */
    private static function resolveThreshold(): int
    {
        $configured = defined('ABTF_LOG_LEVEL') ? strtolower((string) ABTF_LOG_LEVEL) : self::DEFAULT_LEVEL;

        return self::LEVELS[$configured] ?? self::LEVELS[self::DEFAULT_LEVEL];
    }
}