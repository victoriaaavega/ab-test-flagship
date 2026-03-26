<?php

/**
 * Contract that any decision engine must fulfill
 */
interface DecisionAdapterInterface {
    /**
     * Decides which variant a visitor should see for a specific experiment
     *
     * @param string $visitorId   Unique visitor identifier from fingerprinting
     * @param string $experimentId Unique experiment identifier
     * @return string The variant name
     */
    public function decide(string $visitorId, string $experimentId): string;
}