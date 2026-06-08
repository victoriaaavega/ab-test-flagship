<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local simulator for the decision adapter.
 * Used in development when Flagship credentials are not configured.
 * Makes deterministic 50/50 decisions locally without any network calls.
 *
 * Returns null Flagship IDs since there is no Flagship campaign behind it —
 * ExperimentRunner detects the null IDs and skips the activate hit accordingly.
 */
class SimulatorAdapter implements DecisionAdapterInterface {

    /**
     * Decides which variant a visitor should see using local bucketing.
     * The decision is deterministic: same visitorId + experimentId always
     * returns the same variant. Uses crc32 for a 50/50 split.
     *
     * @param string $visitorId    Unique visitor identifier
     * @param string $experimentId Unique experiment identifier (flag key)
     * @return array{variant: string, variationGroupId: null, variationId: null}
     */
    public function decide(string $visitorId, string $experimentId): array {
        $bucket  = abs(crc32($visitorId . $experimentId)) % 100;
        $variant = $bucket < 50 ? 'control' : 'variation_b';

        error_log("[AB Test] SimulatorAdapter decision for '{$experimentId}': {$variant} (bucket: {$bucket})");

        return [
            'variant'          => $variant,
            'variationGroupId' => null,
            'variationId'      => null,
        ];
    }
}