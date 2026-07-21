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
        // In local mode conversions always live in SQL, never in Redis. The
        // local engine decides variants without Redis, so conversion tracking
        // stays consistent: one storage path, Redis-independent, no orphaned
        // rows when Redis comes back. Redis DB 3 is used for conversions only
        // in Flagship mode.
        if (DecisionMode::isLocal()) {
            return $this->recordLocal($experimentId, $variant, $eventName, $visitorId);
        }

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
     * Persists a conversion to SQL when Redis is unavailable in local mode.
     *
     * One row per unique (experiment + variant + event + visitor). INSERT
     * IGNORE against the UNIQUE KEY means repeat clicks by the same visitor are
     * dropped silently, so COUNT(*) over a combo is the unique conversion count
     * (see Database::createConversionsLocalTable).
     *
     * @return bool True if a new unique row was inserted; false on a duplicate
     *              (already converted) or a SQL error. Both mean "no new unique
     *              conversion stored", which the endpoint reports honestly.
     */
    private function recordLocal(
        string $experimentId,
        string $variant,
        string $eventName,
        string $visitorId
    ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'ab_test_conversions_local';

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                    (experiment_id, variant, event_name, visitor_id)
                 VALUES (%s, %s, %s, %s)",
                $experimentId,
                $variant,
                $eventName,
                $visitorId
            )
        );

        if ($result === false) {
            error_log('[AB Test] ConversionTracker recordLocal error: ' . ($wpdb->last_error ?: 'unknown'));
            return false;
        }

        Logger::debug("Conversion recorded locally (SQL). Experiment: {$experimentId}, Variant: {$variant}, Event: {$eventName}");

        // 1 = new unique inserted, 0 = duplicate ignored.
        return $result === 1;
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
     * Reads are pipelined: a single round-trip fetches every total (GET) and
     * every unique (PFCOUNT) at once, instead of 2N sequential round-trips.
     * This matters when Redis is remote (e.g. Kinsta in production), where
     * per-command latency would otherwise dominate the dashboard load time.
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
            // 1. Discover every combo via SCAN over the total-counter keys.
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

            if (empty($combos)) {
                return [];
            }

            // 2. Pipeline all reads: for each combo queue a GET (total) and a
            //    PFCOUNT (unique) in deterministic order, then exec once.
            $ordered  = array_values($combos);

            // Deterministic order so the dashboard lists variants the same way
            // on every load. SCAN returns combos in arbitrary order, which made
            // e.g. v1 sometimes appear above control. Sort by experiment, then
            // event, then variant — with the 'control' baseline always first.
            usort($ordered, static function (array $a, array $b): int {
                return [$a['experiment_id'], $a['event_name'], $a['variant'] === 'control' ? 0 : 1, $a['variant']]
                   <=> [$b['experiment_id'], $b['event_name'], $b['variant'] === 'control' ? 0 : 1, $b['variant']];
            });

            $pipeline = $redis->multi(Redis::PIPELINE);

            foreach ($ordered as $combo) {
                $totalKey  = $this->buildKey(self::TOTAL_PREFIX, $combo['experiment_id'], $combo['variant'], $combo['event_name']);
                $uniqueKey = $this->buildKey(self::UNIQUE_PREFIX, $combo['experiment_id'], $combo['variant'], $combo['event_name']);
                $pipeline->get($totalKey);
                $pipeline->pfCount($uniqueKey);
            }

            $replies = $pipeline->exec();

            // 3. Map the flat replies back to combos. Two replies per combo,
            //    in the same order they were queued: [total, unique, total, ...].
            $rows = [];
            foreach ($ordered as $i => $combo) {
                $totalReply  = $replies[$i * 2]       ?? false;
                $uniqueReply = $replies[$i * 2 + 1]   ?? false;

                $rows[] = [
                    'experiment_id' => $combo['experiment_id'],
                    'variant'       => $combo['variant'],
                    'event_name'    => $combo['event_name'],
                    'total'         => $totalReply !== false ? (int) $totalReply : 0,
                    'unique'        => (int) $uniqueReply,
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