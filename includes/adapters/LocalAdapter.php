<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local decision engine. Decides variants entirely on this server, with no
 * network calls and no external service — the plugin's built-in A/B engine.
 *
 * Used when the administrator explicitly selects Local mode (see DecisionMode).
 * Bucketing is deterministic: the same visitorId + experimentId always yields
 * the same variant, via crc32 over the pair for a 50/50 split.
 *
 * Unlike the old SimulatorAdapter, the variant names are NOT hardcoded: they
 * are read from the experiment's own variant_a / variant_b columns, so the
 * variants a visitor is bucketed into are the real variants the site owner
 * defined. variant_a is the baseline (the low half of the hash).
 *
 * Returns null Flagship IDs: there is no Flagship campaign behind a local
 * decision, so ExperimentRunner skips the activate hit (which is correct —
 * nothing to activate remotely).
 */
class LocalAdapter implements DecisionAdapterInterface {

    /**
     * Per-request cache of an experiment's variant pair, keyed by flag key.
     * Avoids re-querying when the same experiment is decided more than once
     * in a single request.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private static array $variantCache = [];

    /**
     * @return array{variant: string, variationGroupId: null, variationId: null}
     */
    public function decide(string $visitorId, string $experimentId): array {
        [$variantA, $variantB] = $this->resolveVariants($experimentId);

        $bucket  = abs(crc32($visitorId . $experimentId)) % 100;
        $variant = $bucket < 50 ? $variantA : $variantB;

        Nofliq_Logger::debug("LocalAdapter decision for '{$experimentId}': {$variant} (bucket: {$bucket})");

        return [
            'variant'          => $variant,
            'variationGroupId' => null,
            'variationId'      => null,
        ];
    }

    /**
     * Reads variant_a / variant_b for the experiment, with a safe fallback to
     * the historical defaults if the row or columns are somehow missing.
     *
     * @return array{0: string, 1: string} [variantA, variantB]
     */
    private function resolveVariants(string $experimentId): array {
        if (isset(self::$variantCache[$experimentId])) {
            return self::$variantCache[$experimentId];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ab_test_experiments';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT variant_a, variant_b FROM {$table} WHERE flag_key = %s LIMIT 1",
                $experimentId
            )
        );

        $variantA = ($row && $row->variant_a !== '') ? (string) $row->variant_a : 'control';
        $variantB = ($row && $row->variant_b !== '') ? (string) $row->variant_b : 'variation_b';

        self::$variantCache[$experimentId] = [$variantA, $variantB];

        return self::$variantCache[$experimentId];
    }
}