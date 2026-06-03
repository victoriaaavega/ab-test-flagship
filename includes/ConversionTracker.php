<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks conversion events in Redis for real-time reporting at scale.
 *
 * Designed for high traffic (200k+ visitors/day). Instead of storing one row
 * per hit, it aggregates at write time using two Redis structures per
 * experiment + variant + event combination:
 *
 *   - A plain counter (INCR) for total conversions (every click counts).
 *   - A HyperLogLog (PFADD / PFCOUNT) for unique conversions (one click per
 *     visitor counts). HyperLogLog uses a fixed ~12 KB per key regardless of
 *     how many visitors are added, with ~0.81% standard error — ideal for a
 *     conversion dashboard where exact precision is not required.
 *
 * Uses Redis DB 3 to stay separated from variants (DB 0), hit cache (DB 1),
 * and the rate limiter (DB 2).
 *
 * All operations fail silently (fail-open) so a Redis outage never blocks the
 * event endpoint or affects the user. The SQL backup table (populated by
 * StatsRebuildJob) is the persistent fallback when Redis is unavailable.
 */
class ConversionTracker
{
    private const REDIS_HOST    = '127.0.0.1';
    private const REDIS_PORT    = 6379;
    private const REDIS_TIMEOUT = 0.05; // 50ms
    private const REDIS_DB      = 3;

    private const TOTAL_PREFIX  = 'abtf:conv:total:';
    private const UNIQUE_PREFIX = 'abtf:conv:unique:';

    private static ?Redis $instance = null;
    private static bool $failed     = false;

    /**
     * Records a single conversion hit for an experiment + variant + event.
     * Increments the total counter and adds the visitor to the unique HLL.
     *
     * Fails silently if Redis is unavailable — the hit is still sent to
     * Flagship by the caller regardless.
     *
     * @param string $experimentId Flag key
     * @param string $variant      Variant served (e.g. 'control', '1')
     * @param string $eventName    Goal/event name (e.g. 'Nav CTA')
     * @param string $visitorId    64-char visitor ID
     * @return bool True if recorded, false if Redis was unavailable
     */
    public function record(
        string $experimentId,
        string $variant,
        string $eventName,
        string $visitorId
    ): bool {
        $redis = $this->getConnection();

        if ($redis === null) {
            return false;
        }

        try {
            $totalKey  = $this->buildKey(self::TOTAL_PREFIX, $experimentId, $variant, $eventName);
            $uniqueKey = $this->buildKey(self::UNIQUE_PREFIX, $experimentId, $variant, $eventName);

            $pipeline = $redis->multi(Redis::PIPELINE);
            $pipeline->incr($totalKey);
            $pipeline->pfAdd($uniqueKey, [$visitorId]);
            $pipeline->exec();

            return true;
        } catch (Exception $e) {
            error_log('[AB Test] ConversionTracker record error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns the total conversions (all clicks) for one combination.
     *
     * @return int 0 if the key does not exist or Redis is unavailable
     */
    public function getTotal(string $experimentId, string $variant, string $eventName): int
    {
        $redis = $this->getConnection();

        if ($redis === null) {
            return 0;
        }

        try {
            $key   = $this->buildKey(self::TOTAL_PREFIX, $experimentId, $variant, $eventName);
            $value = $redis->get($key);
            return $value !== false ? (int) $value : 0;
        } catch (Exception $e) {
            error_log('[AB Test] ConversionTracker getTotal error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Returns the unique conversions (distinct visitors) for one combination.
     *
     * @return int 0 if the key does not exist or Redis is unavailable
     */
    public function getUnique(string $experimentId, string $variant, string $eventName): int
    {
        $redis = $this->getConnection();

        if ($redis === null) {
            return 0;
        }

        try {
            $key = $this->buildKey(self::UNIQUE_PREFIX, $experimentId, $variant, $eventName);
            return (int) $redis->pfCount($key);
        } catch (Exception $e) {
            error_log('[AB Test] ConversionTracker getUnique error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Lists all tracked conversion combinations currently in Redis.
     * Uses SCAN (never KEYS) to avoid blocking Redis in production.
     *
     * Returns an array of associative rows:
     *   [ ['experiment_id' => ..., 'variant' => ..., 'event_name' => ...,
     *      'total' => int, 'unique' => int], ... ]
     *
     * @return array<int, array{experiment_id: string, variant: string, event_name: string, total: int, unique: int}>
     */
    public function listAll(): array
    {
        $redis = $this->getConnection();

        if ($redis === null) {
            return [];
        }

        try {
            $combos = [];
            $cursor = null;
            $match  = self::TOTAL_PREFIX . '*';

            do {
                $batch = $redis->scan($cursor, $match, 100);
                if ($batch !== false) {
                    foreach ($batch as $key) {
                        $parsed = $this->parseKey($key, self::TOTAL_PREFIX);
                        if ($parsed !== null) {
                            $id = $parsed['experiment_id'] . '|' . $parsed['variant'] . '|' . $parsed['event_name'];
                            $combos[$id] = $parsed;
                        }
                    }
                }
            } while ($cursor !== 0 && $cursor !== null);

            $rows = [];
            foreach ($combos as $combo) {
                $rows[] = [
                    'experiment_id' => $combo['experiment_id'],
                    'variant'       => $combo['variant'],
                    'event_name'    => $combo['event_name'],
                    'total'         => $this->getTotal($combo['experiment_id'], $combo['variant'], $combo['event_name']),
                    'unique'        => $this->getUnique($combo['experiment_id'], $combo['variant'], $combo['event_name']),
                ];
            }

            return $rows;
        } catch (Exception $e) {
            error_log('[AB Test] ConversionTracker listAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Reports whether Redis is reachable for conversion tracking.
     */
    public function isAvailable(): bool
    {
        return $this->getConnection() !== null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Builds a Redis key. The event name is encoded so spaces and colons in
     * goal names (e.g. 'Nav CTA') never break the key structure.
     */
    private function buildKey(string $prefix, string $experimentId, string $variant, string $eventName): string
    {
        return $prefix
            . rawurlencode($experimentId) . ':'
            . rawurlencode($variant) . ':'
            . rawurlencode($eventName);
    }

    /**
     * Reverses buildKey() to recover the three components from a full key.
     *
     * @return array{experiment_id: string, variant: string, event_name: string}|null
     */
    private function parseKey(string $key, string $prefix): ?array
    {
        $suffix = substr($key, strlen($prefix));
        $parts  = explode(':', $suffix);

        if (count($parts) !== 3) {
            return null;
        }

        return [
            'experiment_id' => rawurldecode($parts[0]),
            'variant'       => rawurldecode($parts[1]),
            'event_name'    => rawurldecode($parts[2]),
        ];
    }

    /**
     * Returns a shared Redis connection on DB 3, or null if unavailable.
     */
    private function getConnection(): ?Redis
    {
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
            error_log('[AB Test] ConversionTracker connection failed: ' . $e->getMessage());
            self::$failed = true;
            return null;
        }
    }
}