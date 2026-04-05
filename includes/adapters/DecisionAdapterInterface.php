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
     * @param string $visitorId    Unique visitor identifier from fingerprinting
     * @param string $experimentId Unique experiment identifier (flag key in Flagship)
     * @return string The variant name
     */
    public function decide(string $visitorId, string $experimentId): string;
}