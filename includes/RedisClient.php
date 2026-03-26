<?php

/**
 * Handles all Redis operations for AB test variant storage, uses a Singleton connection to avoid opening
 * multiple connections per request, even when multiple experiments run on the same page
 */
class RedisClient {

    private static ?Redis $instance = null;
    private static bool $failed     = false;

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
     * Returns null if no assignment is found
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

        $key    = "ab_test:variant:{$experimentId}:{$visitorId}";
        $result = $redis->get($key);

        return $result !== false ? $result : null;
    }

    /**
     * Saves the assigned variant for a visitor and experiment, variants are stored for 30 days
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

        $key = "ab_test:variant:{$experimentId}:{$visitorId}";
        $ttl = 60 * 60 * 24 * 30;

        return $redis->setex($key, $ttl, $variant);
    }

    /**
     * Returns a single shared Redis connection for the entire request lifecycle
     * If the connection already failed, returns null
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
            $redis->connect('127.0.0.1', 6379, 0.05); // 50ms timeout
            self::$instance = $redis;
            return self::$instance;
        } catch (Exception $e) {
            error_log('[AB Test] Redis connection failed: ' . $e->getMessage());
            self::$failed = true;
            return null;
        }
    }
}