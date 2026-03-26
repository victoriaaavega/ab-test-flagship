<?php

/**
 * Simulates AB Tasty Flagship SDK in Bucketing mode
 */
class SimulatorAdapter implements DecisionAdapterInterface {

    private const TRAFFIC_SPLIT = 50;

    /**
     * Decides which variant a visitor should see
     *
     * @param string $visitorId
     * @param string $experimentId
     * @return string control or variation_b
     */
    public function decide(string $visitorId, string $experimentId): string {
        $bucket = abs(crc32($visitorId . $experimentId)) % 100;

        return $bucket < self::TRAFFIC_SPLIT ? 'control' : 'variation_b';
    }
}