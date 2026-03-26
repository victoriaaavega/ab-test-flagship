<?php

require_once ABTF_PLUGIN_DIR . 'vendor/autoload.php';

use Flagship\Flagship;
use Flagship\Config\FlagshipConfig;
use Flagship\Enum\LogLevel;
use Flagship\Enum\CacheStrategy;

if (class_exists('FlagshipAdapter')) {
    return;
}

class FlagshipAdapter implements DecisionAdapterInterface {

    private static bool $initialized = false;

    /**
     * Initializes the Flagship SDK once per request
     */
    private function initialize(): void {
    if (self::$initialized) {
        return;
    }

    Flagship::start(
        FLAGSHIP_ENV_ID,
        FLAGSHIP_API_KEY,
        FlagshipConfig::decisionApi()
            ->setLogLevel(LogLevel::ERROR)
            ->setHitCacheImplementation(new HitCacheRedis())
            ->setCacheStrategy(CacheStrategy::BATCHING_AND_CACHING_ON_FAILURE)
    );

    self::$initialized = true;
}

    /**
     * Decides which variant a visitor should see
     *
     * @param string $visitorId
     * @param string $experimentId
     * @return string
     */
    public function decide(string $visitorId, string $experimentId): string {
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

            return $value;

        } catch (\Exception $e) {
            error_log("[AB Test] Flagship error: " . $e->getMessage() . ". Serving control.");
            return 'control';
        }
    }
}