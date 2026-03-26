<?php

if (!defined('ABSPATH')) {
    exit;
}

use Flagship\Cache\IHitCacheImplementation;

class HitCacheRedis implements IHitCacheImplementation {

    private Redis $redis;

    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379, 0.05);
        $this->redis->select(1); // separate DB index from variant cache
    }

    /**
     * Caches hits that failed to send
     *
     * @param array $hits
     */
    public function cacheHit(array $hits): void {
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
        try {
            $keys = $this->redis->keys('*');
            if (empty($keys)) {
                return [];
            }

            $hits    = $this->redis->mGet($keys);
            $hitsOut = [];

            foreach ($hits as $index => $hit) {
                if ($hit) {
                    $hitsOut[$keys[$index]] = json_decode($hit, true);
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
        try {
            $this->redis->flushDB();
        } catch (Exception $e) {
            error_log('[AB Test] HitCacheRedis flushAllHits error: ' . $e->getMessage());
        }
    }
}