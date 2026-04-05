<?php

if (!defined('ABSPATH')) {
    exit;
}

use Flagship\Cache\IHitCacheImplementation;

/**
 * Redis implementation of Flagship's hit cache.
 * Stores failed hits and retries them automatically when Flagship::close() is called.
 */
class HitCacheRedis implements IHitCacheImplementation {

    private const REDIS_HOST    = '127.0.0.1';
    private const REDIS_PORT    = 6379;
    private const REDIS_TIMEOUT = 0.05; // 50ms
    private const REDIS_DB      = 1;    // separate DB index from variant cache

    private ?Redis $redis = null;
    private bool $available = true;

    public function __construct() {
        $this->connect();
    }

    /**
     * Connects to Redis safely
     */
    private function connect(): void {
        try {
            $this->redis = new Redis();
            $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT, self::REDIS_TIMEOUT);
            $this->redis->select(self::REDIS_DB);
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis connection failed: ' . $e->getMessage());
            $this->available = false;
            $this->redis     = null;
        }
    }

    /**
     * Caches hits that failed to send
     *
     * @param array $hits
     */
    public function cacheHit(array $hits): void {
        if (!$this->available || $this->redis === null) {
            return;
        }

        try {
            $pipeline = $this->redis->multi();
            foreach ($hits as $key => $hit) {
                $pipeline->set($key, json_encode($hit));
            }
            $pipeline->exec();
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis cacheHit error: ' . $e->getMessage());
        }
    }

    /**
     * Loads all cached hits to retry sending them
     *
     * @return array
     */
    public function lookupHits(): array {
        if (!$this->available || $this->redis === null) {
            return [];
        }

        try {
            $keys = $this->redis->keys('*');

            if (empty($keys)) {
                return [];
            }

            $hits    = $this->redis->mGet($keys);
            $hitsOut = [];

            foreach ($hits as $index => $hit) {
                if ($hit !== false) {
                    $decoded = json_decode($hit, true);
                    if ($decoded !== null) {
                        $hitsOut[$keys[$index]] = $decoded;
                    }
                }
            }

            return $hitsOut;
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis lookupHits error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Deletes specific hits from cache after they were sent successfully
     *
     * @param array $hitKeys
     */
    public function flushHits(array $hitKeys): void {
        if (!$this->available || $this->redis === null || empty($hitKeys)) {
            return;
        }

        try {
            $this->redis->del($hitKeys);
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis flushHits error: ' . $e->getMessage());
        }
    }

    /**
     * Deletes all cached hits
     */
    public function flushAllHits(): void {
        if (!$this->available || $this->redis === null) {
            return;
        }

        try {
            $this->redis->flushDB();
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis flushAllHits error: ' . $e->getMessage());
        }
    }
}