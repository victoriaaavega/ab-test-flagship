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
 * Requires FLAGSHIP_ENV_ID and FLAGSHIP_API_KEY constants defined in wp-config.php.
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
     * Decides which variant a visitor should see using Flagship SDK.
     * Returns 'control' if the flag is not found or if an error occurs.
     *
     * @param string $visitorId    Unique visitor identifier from fingerprinting
     * @param string $experimentId Flag key as defined in Flagship dashboard
     * @return string
     */
    public function decide(string $visitorId, string $experimentId): string
    {
        $this->initialize();

        try {
            $visitor = Flagship::newVisitor($visitorId, true)->build();
            $visitor->fetchFlags();

            $flag = $visitor->getFlag($experimentId);

            if (!$flag->exists()) {
                error_log("[AB Test] Flag '{$experimentId}' not found in Flagship. Serving control.");
                return 'control';
            }

            $value = $flag->getValue(true, 'control');

            error_log("[AB Test] Flagship decision for '{$experimentId}': {$value}");

            return (string) $value;
        } catch (\Exception $e) {
            error_log('[AB Test] Flagship error: ' . $e->getMessage() . '. Serving control.');
            return 'control';
        }
    }
}
