<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrates the complete AB test flow for a specific experiment.
 * Can be called multiple times on the same page for different experiments.
 *
 * Flow per experiment:
 *   1. Resolve the visitor ID.
 *   2. Look up the variant in Redis (fast path) or DB (fallback).
 *      - New visitor: ask the adapter to decide, then persist the variant AND
 *        the Flagship IDs (variationGroupId, variationId) needed to activate.
 *   3. Send an activate hit to Flagship — once per visitor per experiment,
 *      deduplicated via a Redis flag so we never send it twice (important at
 *      high traffic). The activate must precede any conversion event for the
 *      visitor to be counted in Flagship's reporting.
 */
class ExperimentRunner {

    private Fingerprint $fingerprint;
    private RedisClient $redis;
    private Database $database;
    private DecisionAdapterInterface $adapter;
    private FlagshipActivator $activator;
    private static bool $bootstrapped = false;

    public function __construct(DecisionAdapterInterface $adapter) {
        $this->fingerprint = new Fingerprint();
        $this->redis       = new RedisClient();
        $this->database    = new Database();
        $this->adapter     = $adapter;
        $this->activator   = new FlagshipActivator();
    }

    /**
     * Runs the AB test flow for a specific experiment.
     *
     * @param string $experimentId Flag key as defined in the Flagship dashboard
     * @return array{experimentId: string, visitorId: string, variant: string, source: string}
     */
    public function run(string $experimentId): array {
        $visitorId = $this->fingerprint->generateVisitorId();

        if (!self::$bootstrapped) {
            $this->setCacheBypassHeaders();
            self::$bootstrapped = true;
        }

        if ($this->redis->isAvailable()) {
            $result = $this->handleWithRedis($experimentId, $visitorId);
        } else {
            error_log("[AB Test] Redis unavailable, falling back to database. Experiment: {$experimentId}");
            $result = $this->handleWithDatabase($experimentId, $visitorId);
        }

        // Activate the visitor in Flagship (once per visitor per experiment).
        $this->maybeActivate(
            $experimentId,
            $visitorId,
            $result['variationGroupId'] ?? null,
            $result['variationId'] ?? null
        );

        return [
            'experimentId' => $result['experimentId'],
            'visitorId'    => $result['visitorId'],
            'variant'      => $result['variant'],
            'source'       => $result['source'],
        ];
    }

    /**
     * Handles the experiment flow when Redis is available.
     * Saves variant + Flagship IDs to both Redis and DB to keep them in sync.
     */
    private function handleWithRedis(string $experimentId, string $visitorId): array {
        $stored = $this->redis->getAssignment($experimentId, $visitorId);

        if ($stored !== null) {
            error_log("[AB Test] Returning visitor from Redis. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$stored['variant']}");
            return $this->buildResult($experimentId, $visitorId, $stored['variant'], 'redis', $stored['variationGroupId'], $stored['variationId']);
        }

        $decision = $this->adapter->decide($visitorId, $experimentId);

        $this->redis->saveAssignment($experimentId, $visitorId, $decision['variant'], $decision['variationGroupId'], $decision['variationId']);
        $this->database->saveVariant($experimentId, $visitorId, $decision['variant']);

        error_log("[AB Test] New visitor assigned. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$decision['variant']}");
        return $this->buildResult($experimentId, $visitorId, $decision['variant'], 'redis', $decision['variationGroupId'], $decision['variationId']);
    }

    /**
     * Handles the experiment flow when Redis is unavailable.
     * Uses the database as fallback. Flagship IDs are not persisted in the DB
     * (variant only), so a returning DB-served visitor cannot be re-activated;
     * the activate already happened on their first visit when Redis was up.
     */
    private function handleWithDatabase(string $experimentId, string $visitorId): array {
        $variant = $this->database->getVariant($experimentId, $visitorId);

        if ($variant !== null) {
            error_log("[AB Test] Returning visitor from database. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$variant}");
            return $this->buildResult($experimentId, $visitorId, $variant, 'database', null, null);
        }

        $decision = $this->adapter->decide($visitorId, $experimentId);

        $this->database->saveVariant($experimentId, $visitorId, $decision['variant']);

        error_log("[AB Test] New visitor assigned to database. Experiment: {$experimentId}, Visitor: {$visitorId}, Variant: {$decision['variant']}");
        return $this->buildResult($experimentId, $visitorId, $decision['variant'], 'database', $decision['variationGroupId'], $decision['variationId']);
    }

    /**
     * Sends an activate hit at most once per visitor per experiment.
     *
     * Uses a Redis flag (SET NX) as the dedup guard so that, at high traffic,
     * we never re-send the activate on every page load. Skips entirely when
     * the IDs are missing (e.g. no credentials, control fallback, or a
     * DB-served returning visitor).
     */
    private function maybeActivate(
        string $experimentId,
        string $visitorId,
        ?string $variationGroupId,
        ?string $variationId
    ): void {
        if ($variationGroupId === null || $variationId === null) {
            return; // Nothing to activate (control fallback or no Flagship IDs).
        }

        // Dedup guard: only the first caller for this visitor+experiment wins.
        if (!$this->redis->markActivatedIfFirst($experimentId, $visitorId)) {
            return; // Already activated previously.
        }

        $this->activator->activate($visitorId, $variationGroupId, $variationId);
    }

    /**
     * Builds the result array returned internally, including Flagship IDs.
     */
    private function buildResult(
        string $experimentId,
        string $visitorId,
        string $variant,
        string $source,
        ?string $variationGroupId,
        ?string $variationId
    ): array {
        return [
            'experimentId'     => $experimentId,
            'visitorId'        => $visitorId,
            'variant'          => $variant,
            'source'           => $source,
            'variationGroupId' => $variationGroupId,
            'variationId'      => $variationId,
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