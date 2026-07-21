<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles all Redis operations for AB test variant storage.
 * Uses a Singleton connection to avoid opening multiple connections
 * per request, even when multiple experiments run on the same page.
 *
 * Stores, per visitor + experiment:
 *   - the assigned variant and the Flagship IDs (variationGroupId,
 *     variationId) as a JSON value, so a returning visitor can be re-activated
 *     without calling the decision engine again.
 *   - an activation guard flag used to send the Flagship activate hit only
 *     once per visitor per experiment.
 */
class Nofliq_RedisClient {

    private static ?Redis $instance = null;
    private static bool $failed     = false;

    private const REDIS_HOST    = '127.0.0.1';
    private const REDIS_PORT    = 6379;
    private const REDIS_TIMEOUT = 0.05; // 50ms
    private const REDIS_DB      = 0;     // variant assignments live in DB 0
    private const VARIANT_TTL   = 60 * 60 * 24 * 30; // 30 days
    private const ACTIVATED_TTL = 60 * 60 * 24 * 30; // 30 days

    /**
     * Checks if Redis is available
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
            // Keep state coherent: a failed connection means no usable instance.
            // Otherwise $instance would linger pointing at a dead socket while
            // $failed says there is no connection — a contradiction that a
            // future reconnect path or a reader trusting $instance could trip on.
            self::$failed   = true;
            self::$instance = null;
            return false;
        }
    }

    /**
     * Retrieves the full assignment (variant + Flagship IDs) for a visitor.
     *
     * @return array{variant: string, variationGroupId: string|null, variationId: string|null}|null
     */
    public function getAssignment(string $experimentId, string $visitorId): ?array {
        $redis = $this->getConnection();

        if ($redis === null) {
            return null;
        }

        try {
            $key   = $this->buildKey($experimentId, $visitorId);
            $value = $redis->get($key);

            if ($value === false) {
                return null;
            }

            $decoded = json_decode($value, true);

            // Backward compatibility: older entries stored the bare variant string.
            if (!is_array($decoded)) {
                return [
                    'variant'          => (string) $value,
                    'variationGroupId' => null,
                    'variationId'      => null,
                ];
            }

            return [
                'variant'          => (string) ($decoded['variant'] ?? 'control'),
                'variationGroupId' => $decoded['variationGroupId'] ?? null,
                'variationId'      => $decoded['variationId'] ?? null,
            ];
        } catch (Exception $e) {
            error_log('[AB Test] Redis getAssignment error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Saves the assignment (variant + Flagship IDs) for a visitor.
     */
    public function saveAssignment(
        string $experimentId,
        string $visitorId,
        string $variant,
        ?string $variationGroupId,
        ?string $variationId
    ): bool {
        $redis = $this->getConnection();

        if ($redis === null) {
            return false;
        }

        try {
            $key     = $this->buildKey($experimentId, $visitorId);
            $payload = wp_json_encode([
                'variant'          => $variant,
                'variationGroupId' => $variationGroupId,
                'variationId'      => $variationId,
            ]);
            return $redis->setex($key, self::VARIANT_TTL, $payload);
        } catch (Exception $e) {
            error_log('[AB Test] Redis saveAssignment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Marks the visitor as activated for this experiment, returning true only
     * if this is the first time (i.e. the caller should send the activate hit).
     *
     * Uses SET NX so the operation is atomic: concurrent requests for the same
     * visitor will have exactly one win the guard.
     */
    public function markActivatedIfFirst(string $experimentId, string $visitorId): bool {
        $redis = $this->getConnection();

        if ($redis === null) {
            // Redis down: allow the activate through rather than suppress it.
            return true;
        }

        try {
            $key = $this->buildActivatedKey($experimentId, $visitorId);
            // NX = only set if not exists; EX = expiry in seconds.
            $set = $redis->set($key, '1', ['NX', 'EX' => self::ACTIVATED_TTL]);
            return $set === true;
        } catch (Exception $e) {
            error_log('[AB Test] Redis markActivatedIfFirst error: ' . $e->getMessage());
            return true; // Fail open — better a possible duplicate than a missed activate.
        }
    }

    /**
     * Builds the Redis key for a variant assignment
     */
    private function buildKey(string $experimentId, string $visitorId): string {
        return "ab_test:variant:{$experimentId}:{$visitorId}";
    }

    /**
     * Builds the Redis key for the activation guard flag
     */
    private function buildActivatedKey(string $experimentId, string $visitorId): string {
        return "ab_test:activated:{$experimentId}:{$visitorId}";
    }

    /**
     * Returns a single shared Redis connection for the entire request lifecycle.
     * Selects DB 0 explicitly so the connection never depends on Redis' default
     * database, matching the explicit select() that ConversionTracker does for
     * DB 3. Without this, a misconfigured server default could silently route
     * variant reads/writes to the wrong database.
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
            $redis->select(self::REDIS_DB);
            self::$instance = $redis;
            return self::$instance;
        } catch (Exception $e) {
            error_log('[AB Test] Redis connection failed: ' . $e->getMessage());
            self::$failed = true;
            return null;
        }
    }
}