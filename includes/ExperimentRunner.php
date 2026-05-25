<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the complete AB test flow for a specific experiment.
 * Can be called multiple times on the same page for different experiments.
 */
class ExperimentRunner {

    private Fingerprint $fingerprint;
    private RedisClient $redis;
    private Database $database;
    private DecisionAdapterInterface $adapter;
    private static bool $bootstrapped = false;

    public function __construct(DecisionAdapterInterface $adapter) {
        $this->fingerprint = new Fingerprint();
        $this->redis       = new RedisClient();
        $this->database    = new Database();
        $this->adapter     = $adapter;
    }

    /**
     * Runs the AB test flow for a specific experiment.
     *
     * @param string $experimentId Flag key as defined in Flagship dashboard
     * @return array{experimentId: string, visitorId: string, variant: string, source: string}
     */
    public function run(string $experimentId): array {
        $visitorId = $this->fingerprint->generateVisitorId();

        if (!self::$bootstrapped) {
            $this->setCacheBypassHeaders();
            self::$bootstrapped = true;
        }

        if ($this->redis->isAvailable()) {
            return $this->handleWithRedis($experimentId, $visitorId);
        }

        error_log("[AB Test] Redis unavailable, falling back to database. Experiment: {$experimentId}");
        return $this->handleWithDatabase($experimentId, $visitorId);
    }

    /**
     * Handles the experiment flow when Redis is available.
     * Saves to both Redis and DB to keep them in sync.
     *
     * @param string $experimentId
     * @param string $visitorId
     * @return array
     */
    private function handleWithRedis(string $experimentId, string $visitorId): array {
        $variant = $this->redis->getVariant($experimentId, $visitorId);

        if ($variant !== null) {
            error_log("[AB Test] Returning visitor from Redis. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
            return $this->buildResult($experimentId, $visitorId, $variant, 'redis');
        }

        $variant = $this->adapter->decide($visitorId, $experimentId);

        $this->redis->saveVariant($experimentId, $visitorId, $variant);
        $this->database->saveVariant($experimentId, $visitorId, $variant);

        error_log("[AB Test] New visitor assigned. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
        return $this->buildResult($experimentId, $visitorId, $variant, 'redis');
    }

    /**
     * Handles the experiment flow when Redis is unavailable.
     * Uses database as fallback.
     *
     * @param string $experimentId
     * @param string $visitorId
     * @return array
     */
    private function handleWithDatabase(string $experimentId, string $visitorId): array {
        $variant = $this->database->getVariant($experimentId, $visitorId);

        if ($variant !== null) {
            error_log("[AB Test] Returning visitor from database. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
            return $this->buildResult($experimentId, $visitorId, $variant, 'database');
        }

        $variant = $this->adapter->decide($visitorId, $experimentId);

        $this->database->saveVariant($experimentId, $visitorId, $variant);

        error_log("[AB Test] New visitor assigned to database. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
        return $this->buildResult($experimentId, $visitorId, $variant, 'database');
    }

    /**
     * Builds the result array returned by run().
     *
     * @param string $experimentId
     * @param string $visitorId
     * @param string $variant
     * @param string $source redis or database
     * @return array
     */
    private function buildResult(string $experimentId, string $visitorId, string $variant, string $source): array {
        return [
            'experimentId' => $experimentId,
            'visitorId'    => $visitorId,
            'variant'      => $variant,
            'source'       => $source,
        ];
    }

    /**
     * Sets headers to prevent the page from being served from cache.
     * In production, Kinsta handles this via Nginx rules.
     */
    private function setCacheBypassHeaders(): void {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}