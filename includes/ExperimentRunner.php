<?php

require_once ABTF_PLUGIN_DIR . 'includes/Fingerprint.php';
require_once ABTF_PLUGIN_DIR . 'includes/RedisClient.php';
require_once ABTF_PLUGIN_DIR . 'includes/Database.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/DecisionAdapterInterface.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/SimulatorAdapter.php';
require_once ABTF_PLUGIN_DIR . 'includes/adapters/FlagshipAdapter.php';

/**
 * Orchestrates the complete AB test flow for a specific experiment, can be called multiple times on the
 * same page for different experiments
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
     * @param string $experimentId
     * @return array{experimentId: string, visitorId: string, variant: string, source: string}
     */
    public function run(string $experimentId): array {
        $visitorId = $this->fingerprint->generateVisitorId();

        if (!self::$bootstrapped) {
            $this->setCacheBypassHeaders();
            $this->setHeapIdentityCookie($visitorId);
            self::$bootstrapped = true;
        }

        if ($this->redis->isAvailable()) {
            return $this->handleWithRedis($experimentId, $visitorId);
        }

        error_log("[AB Test] Redis unavailable, falling back to database. Experiment: {$experimentId}");
        return $this->handleWithDatabase($experimentId, $visitorId);
    }

    /**
     * Handles the experiment flow when Redis is available
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

        error_log("[AB Test] New visitor. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
        return $this->buildResult($experimentId, $visitorId, $variant, 'redis');
    }

    /**
     * Handles the experiment flow when Redis is unavailable
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

        error_log("[AB Test] New visitor saved to database. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
        return $this->buildResult($experimentId, $visitorId, $variant, 'database');
    }

    /**
     * Builds the result array returned by run()
     *
     * @param string $experimentId
     * @param string $visitorId
     * @param string $variant
     * @param string $source 'redis' or 'database'
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
     * Sets headers to prevent the page from being served from cache
     */
    private function setCacheBypassHeaders(): void {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Sets a first-party cookie with the visitor ID for Heap identity sync
     *
     * @param string $visitorId
     */
    private function setHeapIdentityCookie(string $visitorId): void {
        if (!isset($_COOKIE['heap_visitor_id'])) {
            setcookie(
                'heap_visitor_id',
                $visitorId,
                [
                    'expires'  => time() + (60 * 60 * 24 * 30),
                    'path'     => '/',
                    'secure'   => false,
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]
            );
        }
    }
}