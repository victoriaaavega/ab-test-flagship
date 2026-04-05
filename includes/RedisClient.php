<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all Redis operations for AB test variant storage.
 * Uses a Singleton connection to avoid opening multiple connections
 * per request, even when multiple experiments run on the same page.
 */
class RedisClient {

    private static ?Redis $instance = null;
    private static bool $failed     = false;

    private const REDIS_HOST    = '127.0.0.1';
    private const REDIS_PORT    = 6379;
    private const REDIS_TIMEOUT = 0.05; // 50ms
    private const VARIANT_TTL   = 60 * 60 * 24 * 30; // 30 days

    /**
     * Checks if Redis is available
     *
     * @return bool
     */
    public function isAvailable(): bool {
        $redis = $this->getConnection();

        if ($redis === null) {
            return false;
        }

        try {
            $pong = $redis->ping();
            return $pong === true || $pong === '+PONG';
        } catch (Exception $e) {
            error_log('[AB Test] Redis ping failed: ' . $e->getMessage());
            self::$failed = true;
            return false;
        }
    }

    /**
     * Retrieves the assigned variant for a visitor and experiment
     *
     * @param string $experimentId
     * @param string $visitorId
     * @return string|null
     */
    public function getVariant(string $experimentId, string $visitorId): ?string {
        $redis = $this->getConnection();

        if ($redis === null) {
            return null;
        }

        try {
            $key    = $this->buildKey($experimentId, $visitorId);
            $result = $redis->get($key);
            return $result !== false ? $result : null;
        } catch (Exception $e) {
            error_log('[AB Test] Redis getVariant error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Saves the assigned variant for a visitor and experiment
     *
     * @param string $experimentId
     * @param string $visitorId
     * @param string $variant
     * @return bool
     */
    public function saveVariant(string $experimentId, string $visitorId, string $variant): bool {
        $redis = $this->getConnection();

        if ($redis === null) {
            return false;
        }

        try {
            $key = $this->buildKey($experimentId, $visitorId);
            return $redis->setex($key, self::VARIANT_TTL, $variant);
        } catch (Exception $e) {
            error_log('[AB Test] Redis saveVariant error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Builds the Redis key for a variant assignment
     *
     * @param string $experimentId
     * @param string $visitorId
     * @return string
     */
    private function buildKey(string $experimentId, string $visitorId): string {
        return "ab_test:variant:{$experimentId}:{$visitorId}";
    }

    /**
     * Returns a single shared Redis connection for the entire request lifecycle
     *
     * @return Redis|null
     */
    private function getConnection(): ?Redis {
        if (self::$failed) {
            return null;
        }

        if (self::$instance !== null) {
            return self::$instance;
        }

        try {
            $redis = new Redis();
            $redis->connect(self::REDIS_HOST, self::REDIS_PORT, self::REDIS_TIMEOUT);
            self::$instance = $redis;
            return self::$instance;
        } catch (Exception $e) {
            error_log('[AB Test] Redis connection failed: ' . $e->getMessage());
            self::$failed = true;
            return null;
        }
    }
}