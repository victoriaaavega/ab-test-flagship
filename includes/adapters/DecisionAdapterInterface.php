<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract that any decision engine must fulfill.
 * Decouples ExperimentRunner from any specific implementation.
 */
interface DecisionAdapterInterface {

    /**
     * Decides which variant a visitor should see for a specific experiment.
     * The decision must be deterministic: same visitorId + experimentId = same variant always.
     *
     * Returns the variant plus the Flagship internal IDs needed to send an
     * activate hit (variationGroupId and variationId). Implementations that
     * are not backed by Flagship may return null for those IDs.
     *
     * @param string $visitorId    Unique visitor identifier
     * @param string $experimentId Flag key as defined in the Flagship dashboard
     * @return array{variant: string, variationGroupId: string|null, variationId: string|null}
     */
    public function decide(string $visitorId, string $experimentId): array;
}