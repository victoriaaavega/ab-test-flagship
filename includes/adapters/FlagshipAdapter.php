<?php

if (!defined('ABSPATH')) {
    exit;
}

use Flagship\Flagship;
use Flagship\Config\FlagshipConfig;
use Flagship\Enum\LogLevel;
use Flagship\Enum\CacheStrategy;

/**
 * Flagship SDK implementation of the decision adapter.
 * Connects to AB Tasty Flagship API to decide which variant a visitor should see.
 * Reads credentials from CredentialsManager (stored encrypted in wp_options).
 *
 * decide() returns the variant plus the Flagship internal IDs
 * (variationGroupId, variationId) needed to send an activate hit. The activate
 * itself is handled explicitly by FlagshipActivator, not by the SDK's batching
 * pool — see ExperimentRunner.
 *
 * If credentials are not configured, decide() safely returns 'control' with null
 * IDs so the site always renders the original version instead of failing.
 */
class FlagshipAdapter implements DecisionAdapterInterface
{

    private static bool $initialized = false;

    /**
     * Initializes the Flagship SDK once per request.
     * Checks for credentials before attempting to start the SDK.
     */
    private function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $envId  = CredentialsManager::getEnvId();
        $apiKey = CredentialsManager::getApiKey();

        if ($envId === null || $apiKey === null) {
            error_log('[AB Test] FlagshipAdapter: credentials not found.');
            return;
        }

        Flagship::start(
            $envId,
            $apiKey,
            FlagshipConfig::decisionApi()
                ->setLogLevel(LogLevel::ERROR)
                ->setHitCacheImplementation(new HitCacheRedis())
                ->setCacheStrategy(CacheStrategy::BATCHING_AND_CACHING_ON_FAILURE)
        );

        self::$initialized = true;
    }

    /**
     * Decides which variant a visitor should see using the Flagship SDK.
     *
     * Returns 'control' with null IDs when credentials are missing, the flag
     * does not exist, or an error occurs — the site then renders the original
     * version and no activate hit is sent.
     *
     * @param string $visitorId    Unique visitor identifier
     * @param string $experimentId Flag key as defined in the Flagship dashboard
     * @return array{variant: string, variationGroupId: string|null, variationId: string|null}
     */
    public function decide(string $visitorId, string $experimentId): array
    {
        if (!CredentialsManager::hasCredentials()) {
            error_log('[AB Test] FlagshipAdapter: no credentials, serving control.');
            return $this->controlResult();
        }

        $this->initialize();

        try {
            $visitor = Flagship::newVisitor($visitorId, true)->build();
            $visitor->fetchFlags();

            $flag = $visitor->getFlag($experimentId);

            if (!$flag->exists()) {
                error_log("[AB Test] Flag '{$experimentId}' not found in Flagship. Serving control.");
                return $this->controlResult();
            }

            // SDK signature is getValue($defaultValue, $userExposed), NOT the
            // other way around. Passing them reversed makes the SDK return the
            // boolean default (true), which casts to "1" — the root cause of
            // every variant showing up as "1" regardless of the real value.
            $value    = (string) $flag->getValue('control', true);
            $metadata = $flag->getMetadata();

            // Metadata exposes the Flagship internal IDs needed for the activate hit.
            $variationGroupId = $metadata->getVariationGroupId() ?: null;
            $variationId      = $metadata->getVariationId() ?: null;

            error_log("[AB Test] Flagship decision for '{$experimentId}': {$value} (vg: {$variationGroupId}, v: {$variationId})");

            return [
                'variant'          => $value,
                'variationGroupId' => $variationGroupId,
                'variationId'      => $variationId,
            ];
        } catch (\Exception $e) {
            error_log('[AB Test] Flagship error: ' . $e->getMessage() . '. Serving control.');
            return $this->controlResult();
        }
    }

    /**
     * The safe fallback result — original version, no activate.
     *
     * @return array{variant: string, variationGroupId: null, variationId: null}
     */
    private function controlResult(): array
    {
        return [
            'variant'          => 'control',
            'variationGroupId' => null,
            'variationId'      => null,
        ];
    }
}