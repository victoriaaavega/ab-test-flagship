<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rate limiter for the AB Test event endpoint.
 * Uses Redis to track request counts per IP within a fixed time window.
 */
class RateLimiter {

    private const REDIS_HOST     = '127.0.0.1';
    private const REDIS_PORT     = 6379;
    private const REDIS_TIMEOUT  = 0.05;
    private const REDIS_DB       = 2;

    private const MAX_REQUESTS   = 20;   // maximum requests allowed per window
    private const WINDOW_SECONDS = 60;   // rolling window duration in seconds
    private const KEY_PREFIX     = 'abtf:rate:';

    private ?Redis $redis       = null;
    private bool $available     = true;
    private static array $cache = []; // stores result per IP for the current request

    /**
     * Lua script that increments the counter and sets TTL only on the first hit,
     * so the window always starts from the first request.
     * Returns the current count after incrementing.
     */
    private const LUA_SCRIPT = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if current == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return current
    LUA;

    public function __construct() {
        $this->connect();
    }

    /**
     * Checks whether the given IP is within the allowed rate limit.
     *
     * Uses a static cache to avoid double-counting: WordPress can call
     * permission_callback more than once per request internally, which
     * would otherwise increment the Redis counter twice per hit.
     *
     * @param string $ip Raw client IP address
     * @return bool True if the request is allowed, false if rate limited
     */
    public function isAllowed(string $ip): bool {
        $hash = $this->hashIp($ip);

        if (isset(self::$cache[$hash])) {
            return self::$cache[$hash];
        }

        if (!$this->available || $this->redis === null) {
            Logger::debug('RateLimiter: Redis unavailable, failing open.');
            return true;
        }

        try {
            $key   = self::KEY_PREFIX . $hash;
            $count = $this->redis->eval(
                self::LUA_SCRIPT,
                [$key, (string) self::WINDOW_SECONDS],
                1
            );

            if ($count === false) {
                Logger::debug('RateLimiter: eval returned false, failing open.');
                return true;
            }

            $allowed = (int) $count <= self::MAX_REQUESTS;

            if (!$allowed) {
                Logger::info("RateLimiter: IP {$hash} exceeded limit ({$count}/" . self::MAX_REQUESTS . " in " . self::WINDOW_SECONDS . "s).");
            }

            self::$cache[$hash] = $allowed;
            return $allowed;

        } catch (Exception $e) {
            Logger::debug('RateLimiter error: ' . $e->getMessage() . ' — failing open.');
            return true;
        }
    }

    /**
     * Hashes the IP to avoid storing raw IPs in Redis.
     *
     * @param string $ip
     * @return string
     */
    private function hashIp(string $ip): string {
        return hash('sha256', $ip);
    }

    /**
     * Connects to Redis on DB index 2.
     * Marks as unavailable on failure so all subsequent calls fail open.
     */
    private function connect(): void {
        try {
            $this->redis = new Redis();
            $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT, self::REDIS_TIMEOUT);
            $this->redis->select(self::REDIS_DB);
        } catch (Exception $e) {
            Logger::error('RateLimiter connection failed: ' . $e->getMessage());
            $this->available = false;
            $this->redis     = null;
        }
    }
}